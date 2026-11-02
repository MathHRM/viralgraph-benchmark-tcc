import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { spawn } from 'node:child_process'
import { appendFile, mkdir, readFile, readdir, rename, writeFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

function loadControl() {
  let childProcess = null
  let simulationTimer = null
  let run = null
  let metricsTimer = null
  let metricsBusy = false
  const reportsDirectory = new URL('./reports/', import.meta.url)
  const reportsPath = fileURLToPath(reportsDirectory)
  const localAddress = (address = '') => address === '::1' || address === '127.0.0.1' || address.startsWith('::ffff:127.')

  async function readReports() {
    await mkdir(reportsPath, { recursive: true })
    const entries = await readdir(reportsPath, { withFileTypes: true })
    const reports = await Promise.all(entries.filter((entry) => entry.isDirectory()).map(async (entry) => {
      try { return JSON.parse(await readFile(path.join(reportsPath, entry.name, 'report.json'), 'utf8')) } catch { return null }
    }))
    const grouped = new Map()
    for (const report of reports.filter(Boolean)) {
      if (!grouped.has(report.runId)) grouped.set(report.runId, { schemaVersion: 2, runId: report.runId, startedAt: report.startedAt, finishedAt: report.finishedAt, requestedLoad: report.requestedLoad, systems: {} })
      const existing = grouped.get(report.runId)
      if (report.systems) Object.assign(existing.systems, report.systems)
      else existing.systems[report.system] = report
      existing.startedAt ||= report.startedAt
      existing.finishedAt = report.finishedAt || existing.finishedAt
      existing.requestedLoad ||= report.requestedLoad
    }
    return [...grouped.values()].sort((a, b) => b.startedAt.localeCompare(a.startedAt))
  }

  async function saveReport(system, status = run.status) {
    const directory = path.join(reportsPath, `${run.id}-${system}`)
    await mkdir(directory, { recursive: true })
    const measured = system === 'sync' && ['sync', 'both'].includes(run.system)
    let failureEvents = []
    if (measured) {
      try {
        const content = await readFile(path.join(directory, 'k6-events.json'), 'utf8')
        failureEvents = content.split('\n').filter(Boolean).flatMap((line) => {
          try {
            const point = JSON.parse(line)
            const failedCheck = point.metric === 'checks' && point.data?.value === 0
            const failedRequest = point.metric === 'http_req_failed' && point.data?.value === 1
            return failedCheck || failedRequest ? [{ at: point.data.time, metric: point.metric, tags: point.data.tags || {} }] : []
          } catch { return [] }
        })
      } catch {}
    }
    failureEvents.sort((a, b) => new Date(a.at) - new Date(b.at))
    const artifacts = measured ? ['k6-events.json', 'output.log'] : []
    const artifactHashes = {}
    for (const artifact of artifacts) {
      try { artifactHashes[artifact] = createHash('sha256').update(await readFile(path.join(directory, artifact))).digest('hex') } catch {}
    }
    const report = {
      schemaVersion: 1,
      runId: run.id,
      system,
      architecture: system === 'sync' ? 'Laravel / PHP-FPM / MySQL' : 'Hyperf / Swoole / RabbitMQ / Neo4j',
      measurement: measured ? 'real' : 'not_measured',
      status,
      startedAt: run.startedAt,
      finishedAt: run.finishedAt || null,
      requestedLoad: { requestsPerSecond: run.rps, durationSeconds: run.duration, target: run.system },
      samplingIntervalSeconds: 5,
      firstFailureAt: failureEvents[0]?.at || null,
      failureEvents,
      metrics: measured ? run.samples : [],
      artifacts,
      artifactHashesSha256: artifactHashes,
      note: measured ? 'Falha significa resposta HTTP com erro ou check HTTP 200 reprovado. O instante vem do evento bruto do k6.' : 'Esta arquitetura não foi medida nesta execução; não há métricas ou horário de falha reais.' ,
    }
    if (status === 'running' && run.status !== 'running') return report
    const reportPath = path.join(directory, 'report.json')
    const temporaryPath = `${reportPath}.${process.pid}.${Date.now()}.tmp`
    await writeFile(temporaryPath, `${JSON.stringify(report, null, 2)}\n`)
    await rename(temporaryPath, reportPath)
    const systems = {}
    for (const architecture of ['sync', 'async']) {
      try { systems[architecture] = JSON.parse(await readFile(path.join(reportsPath, `${run.id}-${architecture}`, 'report.json'), 'utf8')) } catch {}
    }
    const aggregateDirectory = path.join(reportsPath, run.id)
    await mkdir(aggregateDirectory, { recursive: true })
    const aggregatePath = path.join(aggregateDirectory, 'report.json')
    const aggregateTemporaryPath = `${aggregatePath}.${process.pid}.${Date.now()}.tmp`
    await writeFile(aggregateTemporaryPath, `${JSON.stringify({ schemaVersion: 2, runId: run.id, startedAt: run.startedAt, finishedAt: run.finishedAt || null, requestedLoad: { requestsPerSecond: run.rps, durationSeconds: run.duration, target: run.system }, systems }, null, 2)}\n`)
    await rename(aggregateTemporaryPath, aggregatePath)
    return report
  }

  async function sampleMetrics() {
    if (!run || run.status !== 'running' || metricsBusy || !['sync', 'both'].includes(run.system)) return
    metricsBusy = true
    try {
      const query = async (expression) => {
        const response = await fetch(`http://127.0.0.1:9090/api/v1/query?query=${encodeURIComponent(expression)}`)
        if (!response.ok) return null
        const payload = await response.json()
        const value = Number(payload.data?.result?.[0]?.value?.[1])
        return payload.status === 'success' && Number.isFinite(value) ? value : null
      }
      const job = 'sync'
      const requests = `tcc_http_requests_total{job="${job}"}`
      const durationMetric = 'tcc_http_request_duration_seconds'
      const durationSelector = `{job="${job}"}`
      const containers = 'job="cadvisor",container_label_metrics_architecture="synchronous",image!=""'
      const expressions = {
        up: `up{job="${job}"}`,
        requestsPerSecond: `sum(rate(${requests}[1m]))`,
        errorRatePercent: `100 * (sum(rate(tcc_http_requests_total{job="${job}",status_code=~"4..|5.."}[1m])) or vector(0)) / clamp_min((sum(rate(${requests}[1m])) or vector(0)), 1e-12)`,
        averageLatencyMs: `1000 * sum(rate(${durationMetric}_sum${durationSelector}[1m])) / sum(rate(${durationMetric}_count${durationSelector}[1m]))`,
        p95LatencyMs: `1000 * histogram_quantile(0.95, sum by (le) (rate(${durationMetric}_bucket${durationSelector}[1m])))`,
        cpuCores: `sum(rate(container_cpu_usage_seconds_total{${containers}}[1m]))`,
        cpuLimitCores: `sum(container_spec_cpu_quota{${containers}} / container_spec_cpu_period{${containers}})`,
        memoryBytes: `sum(container_memory_working_set_bytes{${containers}})`,
        memoryLimitBytes: `sum(container_spec_memory_limit_bytes{${containers}})`,
      }
      const values = await Promise.all(Object.entries(expressions).map(async ([key, expression]) => [key, await query(expression)]))
      if (run.status !== 'running') return
      run.samples.push({ at: new Date().toISOString(), ...Object.fromEntries(values) })
      await saveReport('sync', 'running')
    } catch {}
    finally { metricsBusy = false }
  }

  return {
    name: 'local-k6-control',
    configureServer(server) {
      server.middlewares.use('/api/load', (request, response) => {
        response.setHeader('Content-Type', 'application/json; charset=utf-8')
        if (!localAddress(request.socket.remoteAddress)) {
          response.statusCode = 403
          return response.end(JSON.stringify({ error: 'Controle de carga disponível apenas localmente.' }))
        }

        const send = (status, body) => {
          response.statusCode = status
          response.end(JSON.stringify(body))
        }

        if (request.method === 'GET' && request.url === '/') return readReports().then((reports) => send(200, { run, history: reports, reports }))
        const reportMatch = request.method === 'GET' && request.url.match(/^\/report\/(\d+)(?:-(sync|async))?$/)
        if (reportMatch) {
          const reportPath = reportMatch[2]
            ? path.join(reportsPath, `${reportMatch[1]}-${reportMatch[2]}`, 'report.json')
            : path.join(reportsPath, reportMatch[1], 'report.json')
          return readFile(reportPath)
            .then((content) => { response.setHeader('Content-Type', 'application/json; charset=utf-8'); response.end(content) })
            .catch(async () => {
              if (reportMatch[2]) return send(404, { error: 'Relatório não encontrado.' })
              const systems = {}
              for (const system of ['sync', 'async']) {
                try { systems[system] = JSON.parse(await readFile(path.join(reportsPath, `${reportMatch[1]}-${system}`, 'report.json'), 'utf8')) } catch {}
              }
              if (!Object.keys(systems).length) return send(404, { error: 'Relatório não encontrado.' })
              const report = { schemaVersion: 2, runId: reportMatch[1], startedAt: systems.sync?.startedAt || systems.async.startedAt, finishedAt: systems.sync?.finishedAt || systems.async.finishedAt, requestedLoad: systems.sync?.requestedLoad || systems.async.requestedLoad, systems }
              response.setHeader('Content-Type', 'application/json; charset=utf-8')
              response.end(JSON.stringify(report))
            })
        }
        if (request.method === 'POST' && request.url === '/start') {
          let body = ''
          request.on('data', (chunk) => { body += chunk })
          request.on('end', async () => {
            let config
            try { config = JSON.parse(body) } catch { return send(400, { error: 'Configuração inválida.' }) }
            const { system, rps, duration } = config
            if (!['sync', 'async', 'both'].includes(system) || !Number.isInteger(rps) || rps < 1 || rps > 1000 || !Number.isInteger(duration) || duration < 10 || duration > 300) {
              return send(400, { error: 'Use uma arquitetura válida, 1–1000 req/s e duração de 10–300 s.' })
            }
            if (childProcess || simulationTimer) return send(409, { error: 'Já existe uma execução em andamento.' })

            const startedAt = new Date().toISOString()
            run = { id: `${Date.now()}`, system, rps, duration, startedAt, status: 'running', output: '', samples: [] }
            if (system === 'async') {
              run.output = 'Execução simulada; nenhum serviço assíncrono foi chamado.'
              simulationTimer = setTimeout(() => {
                run.status = 'completed'
                run.finishedAt = new Date().toISOString()
                simulationTimer = null
                saveReport('async').catch((error) => { run.output += `\nFalha ao salvar relatório: ${error.message}` })
                saveReport('sync', 'not_measured').catch(() => {})
              }, duration * 1000)
            } else {
              run.output = system === 'both' ? 'k6 real no Laravel; série assíncrona simulada no dashboard.' : ''
              const directory = path.join(reportsPath, `${run.id}-sync`)
              await mkdir(directory, { recursive: true })
              await writeFile(path.join(directory, 'output.log'), '')
              await writeFile(path.join(directory, 'k6-events.json'), '')
              await saveReport('sync', 'running')
              const outputWrites = []
              const hostUser = `${process.getuid?.() ?? 1000}:${process.getgid?.() ?? 1000}`
              childProcess = spawn('docker', ['compose', '-f', 'compose.yaml', '-f', 'compose.host.yaml', 'run', '--rm', '--user', hostUser, '-e', 'URL=http://127.0.0.1:8000/api/health', '-e', `RPS=${rps}`, '-e', `DURATION=${duration}s`, 'k6', 'run', '--no-usage-report', '--out', `json=/results/${run.id}-sync/k6-events.json`, '/scripts/carga.js'], { cwd: fileURLToPath(new URL('../k6/', import.meta.url)) })
              childProcess.stdout.on('data', (chunk) => { const output = chunk.toString(); run.output += output; outputWrites.push(appendFile(path.join(directory, 'output.log'), output)) })
              childProcess.stderr.on('data', (chunk) => { const output = chunk.toString(); run.output += output; outputWrites.push(appendFile(path.join(directory, 'output.log'), output)) })
              childProcess.on('error', (error) => { run.status = 'error'; run.output += `\n${error.message}`; outputWrites.push(appendFile(path.join(directory, 'output.log'), `\n${error.message}`)); run.finishedAt = new Date().toISOString(); childProcess = null; clearInterval(metricsTimer); metricsTimer = null; Promise.allSettled(outputWrites).then(() => saveReport('sync', 'error')).catch(() => {}) })
              childProcess.on('close', async (code) => {
                await Promise.allSettled(outputWrites)
                run.status = code === 0 ? 'completed' : run.status === 'stopping' ? 'stopped' : 'error'
                run.exitCode = code
                run.finishedAt = new Date().toISOString()
                childProcess = null
                clearInterval(metricsTimer)
                metricsTimer = null
                await saveReport('sync').catch((error) => { run.output += `\nFalha ao salvar relatório: ${error.message}` })
                await saveReport('async', 'not_measured').catch(() => {})
              })
              sampleMetrics()
              metricsTimer = setInterval(sampleMetrics, 5000)
            }
            return send(202, { run })
          })
          return
        }
        if (request.method === 'POST' && request.url === '/stop') {
          if (!childProcess && !simulationTimer) return send(409, { error: 'Não há execução em andamento.' })
          run.status = 'stopping'
          if (childProcess) childProcess.kill('SIGTERM')
          if (simulationTimer) {
            clearTimeout(simulationTimer)
            simulationTimer = null
            run.status = 'stopped'
            run.finishedAt = new Date().toISOString()
            saveReport('async', 'stopped').catch(() => {})
            saveReport('sync', 'not_measured').catch(() => {})
          }
          return send(202, { run })
        }
        return send(404, { error: 'Rota não encontrada.' })
      })
    },
  }
}

export default defineConfig({
  plugins: [vue(), loadControl()],
  server: {
    proxy: {
      '/prometheus': {
        target: 'http://127.0.0.1:9090',
        rewrite: (path) => path.replace(/^\/prometheus/, ''),
      },
    },
  },
})
