import http from 'k6/http';
import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { Counter, Trend } from 'k6/metrics';

const config = JSON.parse(open(__ENV.CONFIG));
const base = __ENV.BASE_URL.replace(/\/$/, '');
const latency = new Trend('request_latency', true);
const started = new Counter('actions_started');
const completed = new Counter('actions_completed');
const feedWait = new Trend('feed_wait', true);
const cascadeTimeouts = new Counter('cascade_timeouts');
const ms = value => `${Math.round(value * config.time_scale * 1000)}ms`;
const rate = value => Math.max(1, Math.round(value * config.rate_scale));
const duration = 205 * config.time_scale;
const routeNames = ['usuarios_create', 'usuarios_get', 'seguir', 'posts_create',
  'posts_get', 'usuarios_search', 'posts_search', 'feed', 'compartilhar', 'metricas'];
const thresholds = {
  dropped_iterations: ['count==0'],
  checks: ['rate==1'],
};
// Expected 422 searches are successes; malformed bodies still fail checks.
for (const name of routeNames) thresholds[`request_latency{route:${name}}`] = ['max>=0'];
function envelope(flow, end) {
  const stages = flow === 'social'
    ? [{ duration: ms(10), target: rate(2) }, { duration: '0s', target: rate(5) },
      { duration: ms(15), target: rate(5) }]
    : [{ duration: ms(25), target: rate(2) }];
  stages.push({ duration: '0s', target: rate(6) }, { duration: ms(180), target: rate(end) });
  return { executor: 'ramping-arrival-rate', exec: flow, startRate: rate(2),
    timeUnit: '1s', stages, preAllocatedVUs: config.preallocated_vus,
    maxVUs: config.max_vus, gracefulStop: `${config.graceful_stop_seconds}s` };
}
export const options = {
  setupTimeout: '10m', teardownTimeout: '30s',
  summaryTrendStats: ['min', 'avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  systemTags: ['scenario', 'name', 'method', 'status', 'error_code', 'expected_response'],
  thresholds,
  scenarios: {
    social: envelope('social', 600),
    validSearch: envelope('validSearch', 100),
    invalidSearch: envelope('invalidSearch', 40),
    audit: { executor: 'per-vu-iterations', exec: 'audit', vus: 1, iterations: 1,
      startTime: ms(205), maxDuration: `${config.recovery_seconds + 10}s` },
  },
};
function log(kind, data) { console.log(JSON.stringify({ kind, time: Date.now(), ...data })); }
function phase() { return exec.scenario.name === 'default' ? 'setup' : exec.scenario.name; }
function request(method, path, body, route, expected = 200, stage = phase()) {
  const response = http.request(method, `${base}${path}`, body ? JSON.stringify(body) : null, {
    headers: { 'Content-Type': 'application/json' }, timeout: `${config.request_timeout_ms}ms`,
    redirects: 0, tags: { name: route, phase: stage },
    responseCallback: http.expectedStatuses(expected),
  });
  const passed = response.status === expected;
  check(response, { [`${route}: HTTP ${expected}`]: () => passed }, { phase: stage });
  latency.add(response.timings.duration, { route, phase: stage, status: String(response.status),
    success: String(passed), error_code: String(response.error_code || 0) });
  let data = null;
  try { data = response.json(); } catch (_) { /* checks below reject invalid JSON */ }
  log('request', { phase: stage, route, status: response.status, expected, passed,
    error_code: response.error_code || null, body, id: data && data.id,
    action: ['setup', 'audit'].includes(stage) ? null : `${exec.scenario.name}:${exec.scenario.iterationInTest}` });
  return { response, data, passed };
}
function verify(condition, name) {
  check(null, { [name]: () => Boolean(condition) });
  return Boolean(condition);
}
function readMetrics(label) {
  const result = request('GET', '/metricas', null, 'metricas', 200, 'audit');
  const keys = ['usuarios', 'feeds', 'relacoes_seguir', 'posts_criados',
    'compartilhamentos', 'entradas_feed', 'inserts_logicos'];
  const valid = result.passed && result.data && keys.every(key =>
    Number.isSafeInteger(result.data[key]) && result.data[key] >= 0) &&
    keys.slice(0, -1).reduce((sum, key) => sum + result.data[key], 0) === result.data.inserts_logicos;
  verify(valid, 'metricas: componentes inteiros e soma válida');
  log('audit', { label, values: valid ? result.data : null, valid });
  return valid ? result.data : null;
}
function choose(index, salt = 0) {
  // Stateless choices keep external actions independent of VU scheduling.
  let x = (config.seed ^ index ^ salt) >>> 0;
  x = Math.imul(x ^ (x >>> 16), 0x45d9f3b);
  x = Math.imul(x ^ (x >>> 16), 0x45d9f3b);
  return ((x ^ (x >>> 16)) >>> 0) / 4294967296;
}
export function setup() {
  const initial = readMetrics('before_setup');
  if (!initial || initial.inserts_logicos !== 0) throw new Error('A execução exige banco vazio.');
  const users = [];
  for (let i = 0; i < config.users; i++) {
    const payload = { nome: `Pessoa ${i}`, apelido: `s${config.seed}-u${i}`, nascimento: '2000-01-15' };
    const result = request('POST', '/usuarios', payload, 'usuarios_create', 201, 'setup');
    if (!result.passed || !result.data || typeof result.data.id !== 'string') {
      throw new Error('Falha ao preparar usuários.');
    }
    users.push(result.data.id);
    const deadline = Date.now() + config.setup_wait_ms;
    let ready = false;
    while (Date.now() < deadline) {
      const user = http.get(`${base}/usuarios/${result.data.id}`);
      const feed = http.get(`${base}/feed/${result.data.id}`);
      if (user.status === 200 && feed.status === 200) { ready = true; break; }
      sleep(config.feed_poll_ms / 1000);
    }
    if (!ready) throw new Error('Usuário/feed não se tornou consultável na preparação.');
  }
  const edges = [];
  for (let i = 1; i < users.length; i++) {
    // Edge [follower, followed]. Reference: ring; fanout: star; cascade: chain.
    edges.push([i, config.scenario === 'fanout' ? 0 : i - 1]);
  }
  if (config.scenario === 'reference') edges.push([0, users.length - 1]);
  for (const [follower, followed] of edges) {
    if (!request('POST', '/seguir-usuario', { seguidor_id: users[follower],
      seguido_id: users[followed] }, 'seguir', 201, 'setup').passed) throw new Error('Falha no grafo.');
  }
  let baseline = null;
  const deadline = Date.now() + config.setup_wait_ms;
  while (Date.now() < deadline) {
    baseline = readMetrics('setup_observation');
    if (baseline && baseline.usuarios === users.length && baseline.feeds === users.length &&
        baseline.relacoes_seguir === edges.length &&
        baseline.inserts_logicos === users.length * 2 + edges.length) break;
    baseline = null;
    sleep(config.feed_poll_ms / 1000);
  }
  if (!baseline) throw new Error('A base não estabilizou nas contagens esperadas.');
  log('prepared', { users, edges, baseline, config });
  return { users, edges, baseline };
}
function begin() {
  const tags = { flow: exec.scenario.name };
  started.add(1, tags);
  log('action_started', { flow: exec.scenario.name, index: exec.scenario.iterationInTest, scenario_start_ms: exec.scenario.startTime });
  return tags;
}
function page(path, route) {
  const result = request('GET', path, null, route);
  if (!verify(result.passed && result.data && Array.isArray(result.data.data) &&
      result.data.data.length <= 50 && (result.data.proximo_cursor === null ||
      typeof result.data.proximo_cursor === 'string'), `${route}: página válida`)) return null;
  return result.data;
}
function waitForPost(user, post) {
  const start = Date.now();
  const deadline = start + config.feed_wait_ms;
  let cursor = null;
  const cursors = new Set();
  while (Date.now() < deadline) {
    const feed = page(`/feed/${user}${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`, 'feed');
    if (!feed) return false;
    if (feed.data.some(item => item.post && item.post.id === post)) {
      feedWait.add(Date.now() - start);
      return true;
    }
    cursor = feed.proximo_cursor;
    if (cursor && cursors.has(cursor)) { verify(false, 'feed: cursor sem ciclo'); return false; }
    if (cursor) cursors.add(cursor);
    else { cursors.clear(); sleep(config.feed_poll_ms / 1000); }
  }
  cascadeTimeouts.add(1);
  feedWait.add(Date.now() - start);
  log('cascade_timeout', { user, post, wait_ms: Date.now() - start });
  return false;
}
export function social(data) {
  const tags = begin();
  const index = exec.scenario.iterationInTest;
  const actorIndex = config.scenario === 'reference' ? Math.floor(choose(index) * data.users.length) : 0;
  const actor = data.users[actorIndex];
  if (config.scenario === 'growth') {
    const result = request('POST', '/usuarios', { nome: `Nova ${index}`,
      apelido: `s${config.seed}-novo${index}`, nascimento: '2000-01-15' }, 'usuarios_create', 201);
    if (result.passed && result.data && result.data.id) {
      // No retry: immediate visibility failure remains visible in the result.
      request('POST', '/seguir-usuario', { seguidor_id: result.data.id, seguido_id: actor }, 'seguir', 201);
    }
  } else if (choose(index, 1) >= config.post_fraction) {
    page(`/feed/${data.users[Math.floor(choose(index, 2) * data.users.length)]}`, 'feed');
  } else {
    const payload = { usuario_id: actor, conteudo: `viral s${config.seed} acao${index}` };
    const created = request('POST', '/posts', payload, 'posts_create', 201);
    if (created.passed && created.data && created.data.id) {
      const post = created.data.id;
      const location = created.response.headers.Location;
      if (verify(location === `/posts/${post}` || location === `${base}/posts/${post}`, 'post: Location válido')) {
        sleep((1 + Math.floor(choose(index, 3) * 30)) / 1000);
        const read = request('GET', `/posts/${post}`, null, 'posts_get');
        verify(read.data && read.data.id === post && read.data.usuario_id === actor &&
          read.data.conteudo === payload.conteudo, 'post: representação após criação');
      }
      if (config.scenario === 'cascade' || config.scenario === 'reference') {
        const receivers = config.scenario === 'cascade'
          ? data.users.slice(1) : [data.users[(actorIndex + 1) % data.users.length]];
        for (let i = 0; i < receivers.length; i++) {
          if (choose(index, i + 10) >= config.share_fraction) break;
          const user = receivers[i];
          if (!waitForPost(user, post)) break;
          if (!request('POST', '/compartilhar-post', { usuario_id: user, post_id: post }, 'compartilhar', 201).passed) break;
        }
      }
    } else if (created.passed) verify(false, 'post: ID retornado');
  }
  completed.add(1, tags);
}
export function validSearch() {
  const tags = begin();
  const users = exec.scenario.iterationInTest % 2 === 0;
  page(users ? '/usuarios?s=Pessoa' : '/posts?c=viral', users ? 'usuarios_search' : 'posts_search');
  completed.add(1, tags);
}
export function invalidSearch() {
  const tags = begin();
  const users = exec.scenario.iterationInTest % 2 === 0;
  const result = request('GET', users ? '/usuarios' : '/posts', null,
    users ? 'usuarios_search' : 'posts_search', 422);
  verify(result.data && result.data.erro && typeof result.data.erro.codigo === 'string', 'busca inválida: erro JSON');
  completed.add(1, tags);
}
export function audit() {
  log('cut_observation', { label: 'T1', planned_offset_seconds: duration });
  readMetrics('T1_observed');
  const remaining = exec.scenario.startTime + config.recovery_seconds * 1000 - Date.now();
  if (remaining > 0) sleep(remaining / 1000);
  log('cut_observation', { label: 'TF', preserved: false,
    planned_offset_seconds: duration + config.recovery_seconds });
  readMetrics('TF_observed');
}
export function teardown() { readMetrics('TA_observed'); }
export function handleSummary(data) {
  return { [__ENV.SUMMARY_PATH]: JSON.stringify(data, null, 2) };
}
