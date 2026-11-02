<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const tabs = [
  { id: 'overview', label: 'Visão geral', icon: 'M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z' },
  { id: 'runs', label: 'Execuções', icon: 'M8 5H5v15h14V5h-3M9 3h6v4H9zM8 12h8M8 16h5' },
  { id: 'systems', label: 'Arquiteturas', icon: 'M4 5h16M4 12h16M4 19h16M8 5v.01M15 12v.01M10 19v.01' },
]
const chartDefinitions = [
  { key: 'rps', title: 'Vazão', detail: 'Requisições atendidas', unit: 'req/s', color: 'throughput' },
  { key: 'errorRate', title: 'Erros HTTP', detail: 'Respostas 4xx e 5xx', unit: '%', color: 'errors' },
  { key: 'p95', title: 'Latência p95', detail: '95% das respostas abaixo deste valor', unit: 'ms', color: 'latency' },
  { key: 'cpuPercent', title: 'Processador', detail: 'Uso relativo ao limite do contêiner', unit: '% do limite', color: 'cpu' },
  { key: 'memoryPercent', title: 'Memória', detail: 'Uso relativo ao limite do contêiner', unit: '% do limite', color: 'memory' },
]
const reportChartDefinitions = [
  { key: 'requestsPerSecond', title: 'Vazão', detail: 'Requisições atendidas', unit: 'req/s' },
  { key: 'errorRatePercent', title: 'Erros HTTP', detail: 'Respostas com erro', unit: '%' },
  { key: 'p95LatencyMs', title: 'Latência p95', detail: 'Percentil 95', unit: 'ms' },
  { key: 'cpuPercent', title: 'Processador', detail: 'Uso relativo ao limite', unit: '%' },
  { key: 'memoryPercent', title: 'Memória', detail: 'Uso relativo ao limite', unit: '%' },
  { key: 'averageLatencyMs', title: 'Latência média', detail: 'Tempo médio de resposta', unit: 'ms' },
]
const activeTab = ref('overview')
const lastUpdated = ref(null)
const loadError = ref(false)
const loadState = ref({ run: null, history: [] })
const loadMessage = ref('')
const activeReport = ref(null)
const reportError = ref('')
const reportLoading = ref(false)
const reportKey = ref('')
const selectedSystem = ref('both')
const rps = ref(100)
const duration = ref(30)
const poller = ref(null)
const metrics = ref({ sync: emptyMetrics(), async: emptyMetrics() })
const samples = ref([])
const dataTime = computed(() => lastUpdated.value ? lastUpdated.value.toLocaleTimeString('pt-BR') : 'Aguardando coleta')
const activeTabLabel = computed(() => tabs.find((tab) => tab.id === activeTab.value)?.label || 'Relatório')
const systems = computed(() => [
  { id: 'sync', name: 'Síncrono', stack: 'Laravel · PHP-FPM · MySQL', color: '#65d6a1', ...metrics.value.sync },
  { id: 'async', name: 'Orientado a eventos', stack: 'Hyperf · Swoole · RabbitMQ · Neo4j', color: '#a68bfa', ...metrics.value.async },
])
const runActive = computed(() => ['running', 'stopping'].includes(loadState.value.run?.status))
const activeRun = computed(() => runActive.value ? loadState.value.run : null)
const currentRunLabel = computed(() => activeRun.value?.system === 'both' ? 'Ambas as arquiteturas' : systemName(activeRun.value?.system))
const formattedSamples = computed(() => samples.value.map((sample) => ({
  ...sample,
  sync: { ...sample.sync, cpuPercent: percent(sample.sync.cpuCores, sample.sync.cpuLimit), memoryPercent: percent(sample.sync.memoryBytes, sample.sync.memoryLimit) },
  async: { ...sample.async, cpuPercent: percent(sample.async.cpuCores, sample.async.cpuLimit), memoryPercent: percent(sample.async.memoryBytes, sample.async.memoryLimit) },
})))

function emptyMetrics() {
  return { up: null, rps: null, errorRate: null, average: null, p95: null, cpuCores: null, cpuLimit: null, memoryBytes: null, memoryLimit: null, source: 'none' }
}

function percent(value, limit) {
  return Number.isFinite(value) && Number.isFinite(limit) && limit > 0 ? value / limit * 100 : null
}

async function query(expression) {
  const response = await fetch(`/prometheus/api/v1/query?query=${encodeURIComponent(expression)}`)
  if (!response.ok) throw new Error('Prometheus indisponível')
  const payload = await response.json()
  if (payload.status !== 'success') throw new Error('Consulta Prometheus falhou')
  const sample = payload.data.result[0]
  if (!sample) return null
  const value = Number(sample.value[1])
  return Number.isFinite(value) ? value : null
}

async function getSystemMetrics(id) {
  const requests = `tcc_http_requests_total{job="${id}"}`
  const durationMetric = 'tcc_http_request_duration_seconds'
  const durationSelector = `{job="${id}"}`
  const architecture = id === 'sync' ? 'synchronous' : 'asynchronous'
  const containers = `job="cadvisor",container_label_metrics_architecture="${architecture}",image!=""`
  const [up, throughput, errors, average, p95, cpuCores, cpuLimit, memoryBytes, memoryLimit] = await Promise.all([
    query(`up{job="${id}"}`),
    query(`sum(rate(${requests}[1m]))`),
    query(`100 * (sum(rate(tcc_http_requests_total{job="${id}",status_code=~"4..|5.."}[1m])) or vector(0)) / clamp_min((sum(rate(${requests}[1m])) or vector(0)), 1e-12)`),
    query(`1000 * sum(rate(${durationMetric}_sum${durationSelector}[1m])) / sum(rate(${durationMetric}_count${durationSelector}[1m]))`),
    query(`1000 * histogram_quantile(0.95, sum by (le) (rate(${durationMetric}_bucket${durationSelector}[1m])))`),
    query(`sum(rate(container_cpu_usage_seconds_total{${containers}}[1m]))`),
    query(`sum(container_spec_cpu_quota{${containers}} / container_spec_cpu_period{${containers}})`),
    query(`sum(container_memory_working_set_bytes{${containers}})`),
    query(`sum(container_spec_memory_limit_bytes{${containers}})`),
  ])

  return { up, rps: throughput, errorRate: errors, average, p95, cpuCores, cpuLimit, memoryBytes, memoryLimit, source: up === 1 ? 'prometheus' : 'none' }
}

function simulatedAsync(at = Date.now()) {
  const run = activeRun.value
  const isTargeted = run && ['async', 'both'].includes(run.system)
  const elapsed = isTargeted ? Math.max(0, (at - new Date(run.startedAt).getTime()) / 1000) : 0
  const progress = isTargeted ? Math.min(1, elapsed / run.duration) : 0
  const wave = Math.sin(at / 17_000) * 1.8 + Math.sin(at / 8_000) * 0.7
  const targetRps = isTargeted ? run.rps * (0.9 + Math.sin(elapsed / 3) * 0.04) : Math.max(1, 4 + wave)
  const cpuLimit = 1
  const memoryLimit = 512 * 1024 * 1024
  const cpuCores = isTargeted ? Math.min(cpuLimit, 0.12 + targetRps / 1000 * 0.75) : 0.08 + Math.max(0, wave) / 100
  const memoryBytes = isTargeted
    ? Math.min(memoryLimit * 0.94, 155 * 1024 * 1024 + progress * 50 * 1024 * 1024 + targetRps * 70_000)
    : 148 * 1024 * 1024 + Math.max(0, wave) * 1024 * 1024
  const p95 = isTargeted ? 18 + Math.max(0, targetRps - 400) * 0.7 : 18 + Math.max(0, wave) * 1.2
  const average = isTargeted ? p95 * 0.58 : p95 * 0.55

  return {
    up: 1,
    rps: targetRps,
    errorRate: isTargeted && targetRps > 700 ? (targetRps - 700) / 60 : 0,
    average,
    p95,
    cpuCores,
    cpuLimit,
    memoryBytes,
    memoryLimit,
    source: 'mock',
  }
}

async function refreshMetrics() {
  try {
    const sync = await getSystemMetrics('sync')
    let asyncMetrics
    try {
      const available = await getSystemMetrics('async')
      asyncMetrics = available.up === 1 ? available : simulatedAsync()
    } catch {
      asyncMetrics = simulatedAsync()
    }

    const updated = { sync, async: asyncMetrics }
    metrics.value = updated
    samples.value = [...samples.value, { at: new Date(), ...updated }].slice(-60)
    loadError.value = false
    lastUpdated.value = new Date()
  } catch {
    loadError.value = true
    const updated = { ...metrics.value, async: simulatedAsync() }
    metrics.value = updated
    samples.value = [...samples.value, { at: new Date(), ...updated }].slice(-60)
    lastUpdated.value = new Date()
  }
}

async function refreshLoadState() {
  try {
    const response = await fetch('/api/load')
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.error || 'Não foi possível consultar as execuções.')
    loadState.value = payload
  } catch (error) {
    loadMessage.value = error.message
  }
}

async function loadReport(runId) {
  const key = runId
  if (reportKey.value === key && (reportLoading.value || activeReport.value)) return
  reportKey.value = key
  reportError.value = ''
  activeReport.value = null
  reportLoading.value = true
  try {
    const response = await fetch(`/api/load/report/${runId}`)
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.error || 'Relatório não encontrado.')
    activeReport.value = payload
  } catch (error) {
    reportError.value = error.message
  } finally {
    reportLoading.value = false
  }
}

function openReport(runId) {
  activeTab.value = 'report'
  const hash = `#report/${runId}`
  if (location.hash !== hash) location.hash = hash
  loadReport(runId)
}

function showReportFromLocation() {
  const match = location.hash.match(/^#report\/(\d+)$/)
  if (match) {
    activeTab.value = 'report'
    loadReport(match[1])
  } else if (activeTab.value === 'report') {
    activeReport.value = null
    reportKey.value = ''
    activeTab.value = 'runs'
  }
}

async function startRun() {
  loadMessage.value = ''
  try {
    const response = await fetch('/api/load/start', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ system: selectedSystem.value, rps: Number(rps.value), duration: Number(duration.value) }),
    })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.error || 'Falha ao iniciar o ensaio.')
    loadState.value = { ...loadState.value, run: payload.run }
    activeTab.value = 'overview'
  } catch (error) {
    loadMessage.value = error.message
  }
}

async function stopRun() {
  loadMessage.value = ''
  try {
    const response = await fetch('/api/load/stop', { method: 'POST' })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.error || 'Falha ao interromper o ensaio.')
    loadState.value.run = payload.run
  } catch (error) {
    loadMessage.value = error.message
  }
}

function chartFor(definition) {
  const values = formattedSamples.value.flatMap((sample) => ['sync', 'async'].map((id) => sample[id][definition.key])).filter(Number.isFinite)
  const maximum = values.length ? Math.max(...values) : 1
  const scale = maximum === 0 ? 1 : Math.ceil(maximum * 1.15 * 10) / 10
  const paths = Object.fromEntries(['sync', 'async'].map((id) => {
    let path = ''
    let active = false
    formattedSamples.value.forEach((sample, index) => {
      const value = sample[id][definition.key]
      if (!Number.isFinite(value)) {
        active = false
        return
      }
      const x = formattedSamples.value.length < 2 ? 580 : index * 580 / (formattedSamples.value.length - 1)
      const y = 108 - value / scale * 100
      path += `${active ? ' L' : ' M'}${x.toFixed(1)} ${y.toFixed(1)}`
      active = true
    })
    return [id, path.trim()]
  }))
  return { scale, paths, labels: [formatAxis(scale), formatAxis(scale / 2), '0'] }
}

function reportMetricValue(sample, key) {
  if (key === 'cpuPercent') return percent(sample.cpuCores, sample.cpuLimitCores)
  if (key === 'memoryPercent') return percent(sample.memoryBytes, sample.memoryLimitBytes)
  return sample[key]
}

function reportChartFor(metrics, definition) {
  const values = metrics.map((sample) => reportMetricValue(sample, definition.key)).filter(Number.isFinite)
  const maximum = values.length ? Math.max(...values) : 1
  const scale = maximum === 0 ? 1 : Math.ceil(maximum * 1.15 * 10) / 10
  let active = false
  const path = metrics.reduce((result, sample, index) => {
    const value = reportMetricValue(sample, definition.key)
    if (!Number.isFinite(value)) { active = false; return result }
    const x = metrics.length < 2 ? 580 : index * 580 / (metrics.length - 1)
    const y = 108 - value / scale * 100
    const segment = `${active ? ' L' : ' M'}${x.toFixed(1)} ${y.toFixed(1)}`
    active = true
    return result + segment
  }, '').trim()
  return { scale, path, labels: [formatAxis(scale), formatAxis(scale / 2), '0'] }
}

function formatAxis(value) {
  return value >= 100 ? Math.round(value).toString() : value >= 10 ? value.toFixed(0) : value.toFixed(1)
}

function formatValue(value, digits = 1, unit = '') {
  return Number.isFinite(value) ? `${value.toFixed(digits)}${unit}` : '—'
}

function resourceValue(value) {
  if (!Number.isFinite(value)) return '—'
  return value >= 1024 ** 3 ? `${(value / 1024 ** 3).toFixed(2)} GB` : `${(value / 1024 ** 2).toFixed(0)} MB`
}

function systemName(id) {
  return ({ sync: 'Síncrono', async: 'Orientado a eventos', both: 'Ambas as arquiteturas' })[id] || 'Arquitetura'
}

const runLabel = (status) => ({ running: 'Em andamento', stopping: 'Interrompendo', stopped: 'Interrompida', completed: 'Concluída', error: 'Falhou' })[status] || 'Sem execução'
const formatDate = (value) => value ? new Date(value).toLocaleString('pt-BR') : '—'

onMounted(() => {
  refreshMetrics()
  refreshLoadState()
  poller.value = window.setInterval(() => { refreshMetrics(); refreshLoadState() }, 5000)
  showReportFromLocation()
  window.addEventListener('hashchange', showReportFromLocation)
})
onUnmounted(() => { window.clearInterval(poller.value); window.removeEventListener('hashchange', showReportFromLocation) })
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="#overview" aria-label="Observa, início" @click.prevent="activeTab = 'overview'"><span class="brand-mark"><i></i><i></i><i></i></span><span>observa<span class="brand-dot">.</span></span></a>
      <div class="workspace-label">LABORATÓRIO</div>
      <nav aria-label="Navegação principal">
        <button v-for="item in tabs" :key="item.id" class="nav-item" :class="{ active: activeTab === item.id }" :aria-current="activeTab === item.id ? 'page' : undefined" @click="activeTab = item.id">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path :d="item.icon" /></svg>{{ item.label }}
        </button>
      </nav>
      <div class="sidebar-bottom"><div class="status-line"><span class="status-dot"></span> Ambiente local</div><div class="sidebar-version">PAINEL EXPERIMENTAL <span>v0.2</span></div></div>
    </aside>

    <main class="main-content">
      <header class="topbar"><div class="breadcrumb">Laboratório <span>/</span> <strong>{{ activeTabLabel }}</strong></div><div class="top-actions"><span class="live-indicator"><i :class="{ offline: loadError }"></i>{{ loadError ? 'COLETA SÍNCRONA INDISPONÍVEL' : 'ATUALIZADO A CADA 5 S' }}</span><div class="avatar">T</div></div></header>

      <template v-if="activeTab === 'overview'">
        <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> OBSERVABILIDADE DE SISTEMAS</div><h1>Comparação de desempenho</h1><p>Estresse controlado com métricas e gráficos ao vivo para as duas arquiteturas.</p></div><div class="heading-meta"><span class="meta-label">ÚLTIMA COLETA</span><strong>{{ dataTime }}</strong></div></section>

        <section class="run-panel" aria-label="Configuração do ensaio">
          <div class="run-copy"><span class="run-icon"><svg viewBox="0 0 24 24"><path d="M4 12h3l2-7 4 14 2-7h5"/></svg></span><div><strong>Gerar carga</strong><span>Relatórios persistidos por arquitetura; o assíncrono ainda não tem medição real.</span></div></div>
          <div class="run-controls"><label>Alvo<select v-model="selectedSystem" :disabled="runActive"><option value="both">Ambos · síncrono real + assíncrono simulado</option><option value="sync">Somente síncrono · carga real</option><option value="async">Somente assíncrono · simulação</option></select></label><label>Req/s<input v-model.number="rps" type="number" min="1" max="1000" :disabled="runActive" /></label><label>Duração<input v-model.number="duration" type="number" min="10" max="300" :disabled="runActive" /><small>segundos</small></label><button v-if="!runActive" class="run-button" @click="startRun"><svg viewBox="0 0 16 16"><path d="M4 2.8v10.4L13 8z"/></svg> Iniciar estresse</button><button v-else class="stop-button" @click="stopRun"><span></span> Interromper</button></div>
        </section>
        <p v-if="loadMessage" class="message error-message" role="alert">{{ loadMessage }}</p>
        <div class="simulation-note"><span>i</span> Síncrono: Prometheus e recursos do contêiner. Assíncrono: dados simulados, identificados em cada cartão; não representam medições reais.</div>

        <section v-if="activeRun" class="active-run-banner"><span class="pulse"></span><div><strong>Estresse em andamento · {{ currentRunLabel }}</strong><small>{{ activeRun.rps }} req/s · {{ activeRun.duration }} s · iniciado {{ formatDate(activeRun.startedAt) }}</small></div><span class="run-status running">AO VIVO</span></section>

        <section class="system-grid" aria-label="KPIs comparativos das arquiteturas">
          <article v-for="system in systems" :key="system.id" class="system-card" :class="system.id">
            <div class="system-head"><div class="system-title"><span class="system-glyph"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 9h6v6H9zM9 1v3m6-3v3M9 20v3m6-3v3M1 9h3m-3 6h3m16-6h3m-3 6h3"/></svg></span><div><h2>{{ system.name }}</h2><p>{{ system.stack }}</p></div></div><span class="system-badge" :class="system.source">{{ system.source === 'mock' ? 'SIMULADO' : system.up === 1 ? 'AO VIVO' : system.up === 0 ? 'OFFLINE' : 'SEM DADOS' }}</span></div>
            <div class="system-state"><span class="state-dot" :class="{ busy: system.up === 1 }"></span>{{ system.source === 'mock' ? 'Valores ilustrativos' : system.up === 1 ? 'Coletando' : system.up === 0 ? 'Sem conexão' : 'Aguardando métrica' }}<span class="state-time">{{ dataTime }}</span></div>
            <div class="system-kpis">
              <div><span>Vazão</span><strong>{{ formatValue(system.rps, 1, ' req/s') }}</strong></div>
              <div><span>Erros HTTP</span><strong>{{ formatValue(system.errorRate, 2, '%') }}</strong></div>
              <div><span>Latência média</span><strong>{{ formatValue(system.average, 1, ' ms') }}</strong></div>
              <div><span>Latência p95</span><strong>{{ formatValue(system.p95, 1, ' ms') }}</strong></div>
              <div><span>Processador</span><strong>{{ formatValue(percent(system.cpuCores, system.cpuLimit), 1, '%') }} <small v-if="Number.isFinite(system.cpuCores)">· {{ system.cpuCores.toFixed(2) }} / {{ system.cpuLimit.toFixed(2) }} cores</small></strong></div>
              <div><span>Memória</span><strong>{{ resourceValue(system.memoryBytes) }} <small v-if="Number.isFinite(system.memoryBytes)">· {{ formatValue(percent(system.memoryBytes, system.memoryLimit), 0, '%') }} do limite</small></strong></div>
            </div>
          </article>
        </section>

        <section class="charts-section"><div class="section-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> JANELA MÓVEL · 5 MIN</div><h2>Métricas ao vivo</h2></div><div class="chart-legend"><span><i class="legend-sync"></i> Síncrono</span><span><i class="legend-async"></i> Assíncrono <em>(mock)</em></span></div></div>
          <div class="chart-grid">
            <article v-for="definition in chartDefinitions" :key="definition.key" class="chart-card">
              <div class="chart-title"><div><span>{{ definition.title }}</span><small>{{ definition.detail }}</small></div><span class="chart-unit">{{ definition.unit }}</span></div>
              <div class="chart-area"><div class="y-labels"><span>{{ chartFor(definition).labels[0] }}</span><span>{{ chartFor(definition).labels[1] }}</span><span>{{ chartFor(definition).labels[2] }}</span></div><svg class="chart" viewBox="0 0 580 110" preserveAspectRatio="none" role="img" :aria-label="`Gráfico comparativo de ${definition.title}`"><path class="gridline" d="M0 8H580M0 58H580M0 108H580"/><path v-if="chartFor(definition).paths.sync" :d="chartFor(definition).paths.sync" class="chart-line line-sync"/><path v-if="chartFor(definition).paths.async" :d="chartFor(definition).paths.async" class="chart-line line-async"/></svg></div>
              <div class="x-labels"><span>{{ samples[0] ? new Date(samples[0].at).toLocaleTimeString('pt-BR') : '—' }}</span><span>agora</span></div>
              <p v-if="samples.length < 2" class="chart-empty">Aguardando mais amostras.</p>
            </article>
          </div>
        </section>
        <footer><span>Atualização a cada 5 s <b>·</b> retenção visual de 5 min</span><span>{{ loadError ? 'Prometheus indisponível para o síncrono' : 'Síncrono: Prometheus · Assíncrono: simulação local' }}</span></footer>
      </template>

      <template v-else-if="activeTab === 'runs'">
        <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> GERENCIAMENTO DE CARGA</div><h1>Execuções</h1><p>Acompanhe o ensaio atual e consulte as execuções recentes.</p></div><button class="run-button" @click="activeTab = 'overview'">Novo ensaio</button></section>
        <section v-if="loadState.run" class="run-detail"><div class="section-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> ÚLTIMA EXECUÇÃO</div><h2>{{ systemName(loadState.run.system) }}</h2></div><span class="run-status" :class="loadState.run.status">{{ runLabel(loadState.run.status) }}</span></div><div class="run-facts"><div><span>Perfil solicitado</span><strong>{{ loadState.run.rps }} req/s · {{ loadState.run.duration }} s</strong></div><div><span>Iniciada em</span><strong>{{ formatDate(loadState.run.startedAt) }}</strong></div><div><span>Alvo real do k6</span><strong>{{ ['sync', 'both'].includes(loadState.run.system) ? 'Laravel · /api/health' : 'Nenhum · dados simulados' }}</strong></div></div><pre v-if="loadState.run.output" class="run-output">{{ loadState.run.output }}</pre><div v-else class="empty-state">{{ loadState.run.system === 'async' ? 'Execução simulada; nenhum serviço assíncrono foi chamado.' : 'Aguardando saída do gerador de carga…' }}</div><button v-if="runActive" class="stop-button" @click="stopRun"><span></span> Interromper ensaio</button></section>
        <section class="comparison-section"><div class="section-heading compact"><div><div class="eyebrow"><span class="eyebrow-line"></span> RELATÓRIOS AUDITÁVEIS</div><h2>Relatórios comparativos</h2></div><span class="units-note">Salvos em code/dashboard/reports</span></div><div class="comparison-table-wrap"><table><thead><tr><th>Execução</th><th>Início</th><th>Primeira falha · síncrono</th><th>Primeira falha · assíncrono</th><th>Relatório</th></tr></thead><tbody><tr v-for="report in loadState.history" :key="report.runId"><td>{{ report.runId }}<small>· {{ report.requestedLoad?.requestsPerSecond }} req/s · {{ report.requestedLoad?.durationSeconds }} s</small></td><td>{{ formatDate(report.startedAt) }}</td><td>{{ formatDate(report.systems?.sync?.firstFailureAt) }}</td><td>{{ report.systems?.async?.measurement === 'real' ? formatDate(report.systems.async.firstFailureAt) : 'Sem medição real' }}</td><td><button class="text-button" @click="openReport(report.runId)">Abrir relatório →</button></td></tr><tr v-if="!loadState.history.length"><td colspan="5" class="empty-table">Nenhum relatório salvo ainda.</td></tr></tbody></table></div></section>
        <p v-if="loadMessage" class="message error-message" role="alert">{{ loadMessage }}</p><footer><span>Eventos brutos do k6 e amostras Prometheus são preservados.</span><span>O relatório assíncrono informa quando não houve medição real.</span></footer>
      </template>

      <template v-else-if="activeTab === 'report'">
        <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> RELATÓRIO AUDITÁVEL</div><h1>Relatório da execução</h1><p>Dados preservados ao final do ensaio, com métricas, falhas e integridade dos arquivos.</p></div><button class="text-button" @click="activeTab = 'runs'; location.hash = 'runs'">← Voltar às execuções</button></section>
        <p v-if="reportLoading" class="empty-state" role="status">Carregando relatório salvo…</p>
        <p v-if="reportError" class="message error-message" role="alert">{{ reportError }}</p>
        <template v-if="activeReport">
          <section class="run-detail"><div class="section-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> COMPARAÇÃO DA EXECUÇÃO</div><h2>{{ activeReport.runId }}</h2></div></div><div class="run-facts"><div><span>Início</span><strong>{{ formatDate(activeReport.startedAt) }}</strong></div><div><span>Fim</span><strong>{{ formatDate(activeReport.finishedAt) }}</strong></div><div><span>Carga solicitada</span><strong>{{ activeReport.requestedLoad.requestsPerSecond }} req/s · {{ activeReport.requestedLoad.durationSeconds }} s</strong></div></div></section>
          <section v-for="systemId in ['sync', 'async']" :key="systemId" class="run-detail"><template v-if="activeReport.systems?.[systemId]"><div class="section-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> {{ activeReport.systems[systemId].architecture }}</div><h2>{{ systemName(systemId) }}</h2></div><span class="run-status" :class="activeReport.systems[systemId].status">{{ runLabel(activeReport.systems[systemId].status) }}</span></div><div class="run-facts"><div><span>Primeira falha</span><strong>{{ formatDate(activeReport.systems[systemId].firstFailureAt) }}</strong></div><div><span>Medição</span><strong>{{ activeReport.systems[systemId].measurement === 'real' ? 'Real' : 'Não realizada' }}</strong></div></div><p class="report-note">{{ activeReport.systems[systemId].note }}</p>
            <h3>Erros registrados</h3><div class="comparison-table-wrap"><table><thead><tr><th>Instante</th><th>Métrica</th><th>Detalhes</th></tr></thead><tbody><tr v-for="(event, index) in activeReport.systems[systemId].failureEvents" :key="`${event.at}-${index}`"><td>{{ formatDate(event.at) }}</td><td>{{ event.metric }}</td><td>{{ JSON.stringify(event.tags) }}</td></tr><tr v-if="!activeReport.systems[systemId].failureEvents?.length"><td colspan="3" class="empty-table">Nenhuma falha registrada.</td></tr></tbody></table></div>
            <h3>Métricas durante o estresse</h3><div v-if="activeReport.systems[systemId].metrics?.length" class="chart-grid report-chart-grid"><article v-for="definition in reportChartDefinitions" :key="definition.key" class="chart-card"><div class="chart-title"><div><span>{{ definition.title }}</span><small>{{ definition.detail }}</small></div><span class="chart-unit">{{ definition.unit }}</span></div><div class="chart-area"><div class="y-labels"><span>{{ reportChartFor(activeReport.systems[systemId].metrics, definition).labels[0] }}</span><span>{{ reportChartFor(activeReport.systems[systemId].metrics, definition).labels[1] }}</span><span>0</span></div><svg class="chart" viewBox="0 0 580 110" preserveAspectRatio="none" role="img" :aria-label="`Gráfico de ${definition.title} durante o estresse`"><path class="gridline" d="M0 8H580M0 58H580M0 108H580"/><path v-if="reportChartFor(activeReport.systems[systemId].metrics, definition).path" :d="reportChartFor(activeReport.systems[systemId].metrics, definition).path" class="chart-line line-sync"/></svg></div><div class="x-labels"><span>{{ formatDate(activeReport.systems[systemId].metrics[0].at) }}</span><span>{{ formatDate(activeReport.systems[systemId].metrics.at(-1).at) }}</span></div></article></div><p v-else class="empty-state">Nenhuma amostra real disponível para gerar gráficos.</p><div class="comparison-table-wrap"><table><thead><tr><th>Instante</th><th>Req/s</th><th>Erros</th><th>Média</th><th>p95</th><th>CPU</th><th>Memória</th></tr></thead><tbody><tr v-for="sample in activeReport.systems[systemId].metrics" :key="sample.at"><td>{{ formatDate(sample.at) }}</td><td>{{ formatValue(sample.requestsPerSecond, 2) }}</td><td>{{ formatValue(sample.errorRatePercent, 2, '%') }}</td><td>{{ formatValue(sample.averageLatencyMs, 2, ' ms') }}</td><td>{{ formatValue(sample.p95LatencyMs, 2, ' ms') }}</td><td>{{ formatValue(sample.cpuCores, 2) }} / {{ formatValue(sample.cpuLimitCores, 2) }}</td><td>{{ resourceValue(sample.memoryBytes) }} / {{ resourceValue(sample.memoryLimitBytes) }}</td></tr><tr v-if="!activeReport.systems[systemId].metrics?.length"><td colspan="7" class="empty-table">Nenhuma amostra real disponível.</td></tr></tbody></table></div>
            <h3>Integridade dos arquivos · SHA-256</h3><div class="comparison-table-wrap"><table><thead><tr><th>Arquivo</th><th>SHA-256</th></tr></thead><tbody><tr v-for="artifact in activeReport.systems[systemId].artifacts" :key="artifact"><td>{{ artifact }}</td><td class="hash-value">{{ activeReport.systems[systemId].artifactHashesSha256[artifact] || 'Indisponível' }}</td></tr><tr v-if="!activeReport.systems[systemId].artifacts?.length"><td colspan="2" class="empty-table">Nenhum artefato de medição real nesta execução.</td></tr></tbody></table></div><a :href="`/api/load/report/${activeReport.runId}-${systemId}`" target="_blank" rel="noopener">JSON do sistema</a></template><p v-else class="empty-state">Este sistema não foi incluído na execução.</p></section>
          <p class="report-note">JSON comparativo: <a :href="`/api/load/report/${activeReport.runId}`" target="_blank" rel="noopener">abrir arquivo salvo</a></p>
        </template>
      </template>

      <template v-else>
        <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> COMPOSIÇÕES BACKEND</div><h1>Arquiteturas</h1><p>Estado de implementação e origem das métricas exibidas.</p></div></section>
        <section class="architecture-grid"><article v-for="system in systems" :key="system.id" class="architecture-card" :class="system.id"><div class="system-head"><div><span class="eyebrow">{{ system.id === 'sync' ? 'FLUXO SÍNCRONO' : 'FLUXO ORIENTADO A EVENTOS' }}</span><h2>{{ system.name }}</h2></div><span class="system-badge" :class="system.source">{{ system.source === 'mock' ? 'SIMULADO' : system.up === 1 ? 'AO VIVO' : system.up === 0 ? 'OFFLINE' : 'SEM DADOS' }}</span></div><p class="architecture-stack">{{ system.stack }}</p><div class="architecture-copy"><h3>Origem dos indicadores</h3><p>{{ system.source === 'mock' ? 'Dados de demonstração no dashboard, recalculados a cada coleta e ajustados quando o ensaio simulado está ativo.' : 'Prometheus coleta vazão e latência da aplicação; cAdvisor coleta CPU e memória dos contêineres.' }}</p><h3>O que é medido</h3><p>Vazão, erros HTTP, latência média e p95, uso de CPU e memória dos contêineres etiquetados para esta arquitetura.</p></div><button class="text-button" @click="selectedSystem = system.id; activeTab = 'overview'">Ver métricas e gerar carga <span>→</span></button></article></section>
        <div class="simulation-note architecture-note"><span>i</span> Quando Hyperf/Swoole estiver disponível, deve expor as mesmas métricas Prometheus, nomes e unidades do Laravel. O dashboard dará preferência à série real `job=async` e usará este mock somente enquanto o alvo não responder.</div>
        <footer><span>Coleta do síncrono: Prometheus a cada 5 s.</span><span>Assíncrono ainda não implementado.</span></footer>
      </template>
    </main>
  </div>
</template>
