// Carga de viralização sobre o snapshot Zipf (code/dataset/generate.py).
//
//   MODE=smoke  30 s, 50 VUs constantes: valida rotas (sem 5xx) e coleta de métricas.
//   MODE=tcc    rampa até VUS_MAX (padrão 600) em ~8 min: coleta para a monografia.
//
// Mistura por iteração:
//   70% leitura   feed de usuário comum | árvore de propagação de post viral (Zipf no ranking)
//   25% escrita   usuário comum cria post | compartilha post viral que está no seu feed
//    5% celebridade publica (fan-out 2000+ seguidores)
//
// Env: BASE_URL, ARCH=sync|async, DATASET (k6/dataset.json), RABBITMQ_METRICS_URL (opcional),
//      VUS_MAX, THINK_MIN_MS, THINK_MAX_MS, ZIPF_S.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Counter, Gauge, Rate } from 'k6/metrics';

const MODE = (__ENV.MODE || 'smoke').toLowerCase();
const ARCH = (__ENV.ARCH || 'sync').toLowerCase();
const BASE = (__ENV.BASE_URL || 'http://127.0.0.1:19998').replace(/\/$/, '');
const QUEUE_URL = __ENV.RABBITMQ_METRICS_URL || '';
const VUS_MAX = parseInt(__ENV.VUS_MAX || '600', 10);
const THINK_MIN = parseInt(__ENV.THINK_MIN_MS || '50', 10);
const THINK_MAX = parseInt(__ENV.THINK_MAX_MS || '250', 10);
const ZIPF_S = parseFloat(__ENV.ZIPF_S || '1.1');
if (!['smoke', 'tcc'].includes(MODE)) throw new Error(`MODE inválido: ${MODE}`);
if (!['sync', 'async'].includes(ARCH)) throw new Error(`ARCH inválido: ${ARCH}`);

// Sync confirma persistência (201); async confirma só a aceitação no broker (202).
const SHARE_OK = ARCH === 'sync' ? 201 : 202;

// Um SharedArray por coleção: o acesso a um elemento desserializa só aquele elemento.
// (Um único elemento com o dataset inteiro seria re-desserializado a cada acesso.)
const load = () => JSON.parse(open(__ENV.DATASET));
const commonUsers = new SharedArray('common_users', () => load().common_users);
const celebrities = new SharedArray('celebrities', () => load().celebrities);
const viralPosts = new SharedArray('viral_posts', () => load().viral_posts);

const shareDuplicates = new Counter('share_duplicates');
const serverErrors = new Counter('http_5xx');
const timeouts = new Counter('http_timeouts'); // status 0: timeout do k6 (30 s) ou conexão recusada
const actions = new Counter('actions');
const queueDepth = new Gauge('rabbitmq_queue_depth');
const businessOk = new Rate('business_ok');

const ROUTES = ['feed', 'propagation_tree', 'posts_create', 'share', 'celebrity_post'];
const thresholds = {
  // Smoke reprova só com 5xx (rota quebrada). Saturação (timeouts, latência) é resultado, não erro.
  'http_req_failed{scenario:traffic}': ['rate>=0'],
  http_5xx: MODE === 'smoke' ? ['count==0'] : ['count>=0'],
  http_timeouts: ['count>=0'],
  business_ok: ['rate>=0'],
};
// Thresholds sempre verdadeiros só para o resumo imprimir p50/p95/p99 e erro por rota.
for (const route of ROUTES) {
  thresholds[`http_req_duration{name:${route}}`] = ['p(99)>=0'];
  thresholds[`http_req_failed{name:${route}}`] = ['rate>=0'];
}

const trafficScenario = MODE === 'smoke'
  ? { executor: 'constant-vus', vus: 50, duration: '30s', exec: 'traffic' }
  : {
    executor: 'ramping-vus', exec: 'traffic', startVUs: 0, gracefulRampDown: '30s',
    stages: [
      { duration: '1m', target: Math.round(VUS_MAX / 6) },
      { duration: '2m', target: Math.round(VUS_MAX / 2) },
      { duration: '2m', target: VUS_MAX },
      { duration: '2m', target: VUS_MAX },
      { duration: '1m', target: 0 },
    ],
  };

const scenarios = { traffic: trafficScenario };
if (QUEUE_URL) {
  for (const queue of ['posts', 'distribution', 'shares']) {
    thresholds[`rabbitmq_queue_depth{queue:${queue}}`] = ['value>=0'];
  }
  scenarios.queue = {
    executor: 'constant-vus', exec: 'queueProbe', vus: 1,
    duration: MODE === 'smoke' ? '40s' : '9m',
  };
}

export const options = {
  scenarios,
  thresholds,
  summaryTrendStats: ['avg', 'min', 'p(50)', 'p(95)', 'p(99)', 'max'],
  discardResponseBodies: false,
};

// Zipf sobre o ranking de posts virais (maior cascata primeiro): poucos posts concentram a leitura.
let viralCdf = null;
function zipfIndex(n) {
  if (!viralCdf) {
    const weights = Array.from({ length: n }, (_, i) => 1 / Math.pow(i + 1, ZIPF_S));
    const total = weights.reduce((a, b) => a + b, 0);
    let acc = 0;
    viralCdf = weights.map(w => (acc += w / total));
  }
  const r = Math.random();
  let lo = 0, hi = n - 1;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (viralCdf[mid] < r) lo = mid + 1; else hi = mid;
  }
  return lo;
}
const pick = list => list[Math.floor(Math.random() * list.length)];
const viralPost = () => viralPosts[zipfIndex(viralPosts.length)];

function call(method, path, body, name, expected) {
  const res = http.request(method, `${BASE}${path}`, body ? JSON.stringify(body) : null, {
    headers: { 'Content-Type': 'application/json' },
    tags: { name },
    timeout: '30s',
    responseCallback: http.expectedStatuses(...expected),
  });
  if (res.status >= 500) serverErrors.add(1, { name });
  if (res.status === 0) timeouts.add(1, { name });
  return res;
}

function readFeed() {
  const res = call('GET', `/feed/${pick(commonUsers)}`, null, 'feed', [200]);
  businessOk.add(check(res, {
    'feed 200': r => r.status === 200,
    'feed envelope': r => r.status === 200 && Array.isArray(r.json('data')),
  }));
}

function readTree() {
  const post = viralPost();
  const res = call('GET', `/posts/${post.id}/propagation-tree`, null, 'propagation_tree', [200]);
  businessOk.add(check(res, {
    'tree 200': r => r.status === 200,
    'tree autor': r => r.status === 200 && r.json('autor_id') === post.author,
  }));
}

function createPost(author, name) {
  const res = call('POST', '/posts', { usuario_id: author, conteudo: `viral k6 ${__VU}-${__ITER}` }, name, [201]);
  businessOk.add(check(res, { [`${name} 201`]: r => r.status === 201 }));
}

// Compartilhamento válido: usuário que tem o post viral no feed, com origem = quem o entregou
// (autor => nível 1; compartilhador => níveis seguintes da árvore). Repetições viram 422.
function share(name) {
  let post = viralPost();
  for (let i = 0; i < 5 && !post.receivers.length; i++) post = viralPost();
  if (!post.receivers.length) return;
  const [user, source] = pick(post.receivers);
  const res = call('POST', `/posts/${post.id}/share`, { usuario_id: user, origem_usuario_id: source },
    name, [SHARE_OK, 422]);
  if (res.status === 422) shareDuplicates.add(1, { name });
  businessOk.add(check(res, { [`${name} ${SHARE_OK}|422`]: r => r.status === SHARE_OK || r.status === 422 }));
}

export function traffic() {
  const r = Math.random();
  let kind;
  if (r < 0.70) {
    kind = 'read';
    if (Math.random() < 0.5) readFeed(); else readTree();
  } else if (r < 0.95) {
    kind = 'common_write';
    if (Math.random() < 0.5) createPost(pick(commonUsers), 'posts_create'); else share('share');
  } else {
    kind = 'celebrity';
    // Celebridade publica: fan-out imediato para 2000+ seguidores (síncrono no Laravel; fila no Hyperf).
    createPost(pick(celebrities), 'celebrity_post');
  }
  actions.add(1, { kind });
  sleep((THINK_MIN + Math.random() * (THINK_MAX - THINK_MIN)) / 1000);
}

// Lê o endpoint Prometheus do RabbitMQ e publica a profundidade das filas como métrica do k6.
export function queueProbe() {
  const res = http.get(QUEUE_URL, { tags: { name: 'rabbitmq_probe' }, timeout: '5s' });
  if (res.status === 200 && res.body) {
    for (const line of res.body.split('\n')) {
      const m = /^rabbitmq_queue_messages\{([^}]*)\}\s+([0-9.eE+-]+)/.exec(line);
      if (!m) continue;
      const queue = /queue="([^"]+)"/.exec(m[1]);
      if (queue) queueDepth.add(parseFloat(m[2]), { queue: queue[1] });
    }
  }
  sleep(1);
}
