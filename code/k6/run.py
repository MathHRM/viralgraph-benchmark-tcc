#!/usr/bin/env python3
"""Executa k6 e consolida seus artefatos em um relatório JSON para visualização."""
import argparse
from collections import Counter, defaultdict
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import subprocess
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parent
FLOWS = ('social', 'validSearch', 'invalidSearch')
RESET_TIMEOUT_SECONDS = 300
RESOURCE_PROFILES = {
    'async': {
        'NGINX_CPUS': '0.10', 'NGINX_MEMORY': '64m',
        'API_CPUS': '0.20', 'API_MEMORY': '320m',
        'WORKER_CPUS': '0.30', 'WORKER_MEMORY': '384m',
        'RABBITMQ_CPUS': '0.20', 'RABBITMQ_MEMORY': '512m',
        'NEO4J_CPUS': '0.50', 'NEO4J_MEMORY': '1400m',
    },
    'sync': {
        'NGINX_CPUS': '0.10', 'NGINX_MEMORY': '64m',
        'API_CPUS': '0.20', 'API_MEMORY': '320m',
        'MYSQL_CPUS': '1.0', 'MYSQL_MEMORY': '2296m',
    },
}
EXPECTED_SERVICES = {
    'async': {'nginx': (.10, 64), 'api1': (.20, 320), 'api2': (.20, 320),
              'worker': (.30, 384), 'rabbitmq': (.20, 512), 'neo4j': (.50, 1400)},
    'sync': {'nginx': (.10, 64), 'api1': (.20, 320), 'api2': (.20, 320), 'mysql': (1.0, 2296)},
}


def compose_command(architecture):
    project = 'tcc-benchmark-async' if architecture == 'async' else 'tcc-benchmark-sync'
    backend = ROOT.parent.parent / 'backends' / ('hyperf-async' if architecture == 'async' else 'laravel-sync')
    compose = ['docker', 'compose', '-p', project]
    if architecture == 'async':
        compose += ['-f', str(backend / 'compose.yaml'), '-f', str(backend / 'compose.benchmark.yaml')]
    else:
        compose += ['-f', str(backend / 'compose.benchmark.yaml')]
    return compose, backend


def reset_commands(architecture):
    compose, backend = compose_command(architecture)
    commands = [(compose + ['down', '--volumes', '--remove-orphans'], backend),
                (compose + ['up', '-d', '--build', '--wait', '--wait-timeout', '300'], backend)]
    if architecture == 'sync':
        commands.append((compose + ['exec', '-T', 'api1', 'php', 'artisan', 'migrate:fresh', '--force'], backend))
    return commands


def wait_for_api(base_url, timeout=RESET_TIMEOUT_SECONDS):
    deadline = time.monotonic() + timeout
    last_error = 'sem resposta HTTP 200'
    while time.monotonic() < deadline:
        try:
            with urlopen(base_url.rstrip('/') + '/metricas', timeout=5) as response:
                if response.status == 200:
                    return
                last_error = f'HTTP {response.status}'
        except (OSError, URLError) as error:
            last_error = str(error)
        time.sleep(2)
    raise RuntimeError(f'API indisponível após {timeout}s em /metricas: {last_error}')


def clean_architecture(architecture, base_url, log, command_runner=subprocess.run,
                       api_waiter=wait_for_api, environment=None):
    commands = reset_commands(architecture)
    missing = [Path(command[index + 1]) for command, _ in commands for index, value in enumerate(command)
               if value == '-f' and not Path(command[index + 1]).is_file()]
    if missing:
        raise RuntimeError(f'Composição da arquitetura {architecture} não encontrada: {missing[0]}')
    for command, cwd in commands:
        log.write(f"$ (cd {cwd} && {' '.join(command)})\n")
        log.flush()
        options = {'cwd': cwd, 'text': True, 'stdout': log, 'stderr': subprocess.STDOUT}
        if environment is not None:
            options['env'] = environment
        result = command_runner(command, **options)
        if result.returncode:
            if 'up' in command:
                logs_command = command[:command.index('up')] + ['logs', '--no-color', '--tail=100']
                log.write(f"\n$ (cd {cwd} && {' '.join(logs_command)})\n")
                log.flush()
                command_runner(logs_command, cwd=cwd, text=True, stdout=log,
                               stderr=subprocess.STDOUT, **({'env': environment} if environment is not None else {}))
            raise RuntimeError(f"Comando de reset falhou ({result.returncode}): {' '.join(command)}")
    api_waiter(base_url)


def load_corpus(architecture, environment, log, command_runner=subprocess.run):
    compose, backend = compose_command(architecture)
    entrypoint = (['php', 'bin/seed.php', '--directory=/benchmark'] if architecture == 'async'
                  else ['php', 'artisan', 'benchmark:load-corpus', '/benchmark'])
    command = compose + ['exec', '-T', 'api1'] + entrypoint
    log.write(f"$ (cd {backend} && {' '.join(command)})\n")
    log.flush()
    result = command_runner(command, cwd=backend, env=environment, text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.stdout:
        log.write(result.stdout)
    if result.stderr:
        log.write(result.stderr)
    log.flush()
    if result.returncode:
        raise RuntimeError(f"Carga do corpus falhou ({result.returncode}): {result.stderr.strip()}")
    report = None
    for line in reversed(result.stdout.splitlines()):
        try:
            report = json.loads(line)
            break
        except json.JSONDecodeError:
            continue
    if report is None:
        raise RuntimeError('O carregador não retornou um resumo JSON válido.')
    if report.get('status') != 'succeeded' or report.get('architecture') != architecture:
        raise RuntimeError('O carregador retornou status ou arquitetura inesperados.')
    return report


def validate_api_counts(base_url, expected, timeout=10):
    with urlopen(base_url.rstrip('/') + '/metricas', timeout=timeout) as response:
        metrics = json.loads(response.read())
    wanted = {
        'usuarios': expected['users'], 'feeds': expected['users'],
        'relacoes_seguir': expected['follows'], 'posts_criados': expected['posts'],
        'compartilhamentos': expected['shares'], 'entradas_feed': expected['feed_items'],
    }
    if any(metrics.get(key) != value for key, value in wanted.items()):
        raise RuntimeError(f'Contagens da API divergem do corpus: esperado {wanted}, recebido {metrics}.')
    return metrics


def inspect_resource_limits(architecture):
    project = 'tcc-benchmark-async' if architecture == 'async' else 'tcc-benchmark-sync'
    ids = subprocess.check_output(['docker', 'ps', '-aq', '--filter', f'label=com.docker.compose.project={project}'],
                                  text=True).split()
    if not ids:
        raise RuntimeError('Docker não encontrou contêineres do projeto de benchmark.')
    containers = json.loads(subprocess.check_output(['docker', 'inspect', *ids], text=True))
    actual = {}
    for container in containers:
        service = container.get('Config', {}).get('Labels', {}).get('com.docker.compose.service')
        if service not in EXPECTED_SERVICES[architecture]:
            continue
        host = container.get('HostConfig', {})
        actual[service] = {'cpus': host.get('NanoCpus', 0) / 1_000_000_000,
                           'memory_bytes': host.get('Memory', 0)}
    missing = set(EXPECTED_SERVICES[architecture]) - actual.keys()
    if missing:
        raise RuntimeError('Contêineres sem limites inspecionáveis: '+', '.join(sorted(missing)))
    for service, (cpu_expected, memory_mib) in EXPECTED_SERVICES[architecture].items():
        limits = actual[service]
        memory_expected = (memory_mib * 1_000_000, memory_mib * 1_048_576)
        memory_matches = any(abs(limits['memory_bytes'] - expected) <= expected * .01
                             for expected in memory_expected)
        if abs(limits['cpus'] - cpu_expected) > .005 or not memory_matches:
            raise RuntimeError(f'Limite de recursos inesperado em {service}: {limits}.')
    return {'status': 'verified', 'services': actual,
            'total_cpus': round(sum(value['cpus'] for value in actual.values()), 3),
            'total_memory_bytes': sum(value['memory_bytes'] for value in actual.values())}


def probe_hub_fanout(base_url, corpus_manifest, wait_seconds=900):
    initial = validate_api_counts(base_url, corpus_manifest['counts'])
    actor = corpus_manifest['distribution']['hub_users'][0]
    expected_feed_delta = corpus_manifest['spec']['users'] - 1
    payload = json.dumps({'usuario_id': actor,
                          'conteudo': f'viral fanout-probe seed{corpus_manifest["seed"]}'}).encode()
    request = Request(base_url.rstrip('/')+'/posts', data=payload, method='POST', headers={
        'Content-Type': 'application/json', 'X-Event-Sequence': '1',
    })
    started = time.monotonic()
    status = None
    post_id = None
    error = None
    try:
        with urlopen(request, timeout=180) as response:
            status = response.status
            body = json.loads(response.read())
            post_id = body.get('id') if isinstance(body, dict) else None
            location = response.headers.get('Location')
            if post_id is None and location:
                post_id = location.rstrip('/').split('/')[-1]
    except HTTPError as response:
        status = response.code
        error = f'HTTP {response.code}'
    except (OSError, URLError) as failure:
        error = str(failure)

    request_latency_ms = (time.monotonic() - started) * 1_000
    deadline = time.monotonic() + wait_seconds
    final = initial
    while time.monotonic() < deadline:
        try:
            with urlopen(base_url.rstrip('/')+'/metricas', timeout=10) as response:
                final = json.loads(response.read())
            if (final.get('posts_criados') == initial['posts_criados'] + 1
                    and final.get('entradas_feed') == initial['entradas_feed'] + expected_feed_delta):
                break
        except (OSError, URLError, json.JSONDecodeError):
            pass
        time.sleep(1)

    materialized = (final.get('posts_criados') == initial['posts_criados'] + 1
                    and final.get('entradas_feed') == initial['entradas_feed'] + expected_feed_delta)
    completed = status == 201 and materialized
    return {
        'status': 'completed' if completed else 'ambiguous' if materialized else 'incomplete',
        'actor_class': 'hub', 'actor_id': actor, 'post_id': post_id,
        'http_status': status, 'request_latency_ms': round(request_latency_ms, 3),
        'fanout_completion_ms': round((time.monotonic() - started) * 1_000, 3),
        'expected_new_feed_items': expected_feed_delta,
        'observed_new_posts': final.get('posts_criados', 0) - initial['posts_criados'],
        'observed_new_feed_items': final.get('entradas_feed', 0) - initial['entradas_feed'],
        'wait_limit_seconds': wait_seconds, 'error': error,
    }


def generate_corpus(output, dataset_config, k6_config, command_runner=subprocess.run):
    config = json.loads(Path(dataset_config).read_text())
    required = {'users', 'posts', 'follows', 'shares', 'feed_limit', 'seed', 'batch_size', 'reference_date'}
    if set(config) != required:
        raise ValueError('dataset-config deve conter exatamente: '+', '.join(sorted(required)))
    if config['seed'] != k6_config['seed'] or config['reference_date'] != k6_config['reference_date']:
        raise ValueError('Seed e reference_date do corpus devem coincidir com a configuração k6.')
    command = [sys.executable, str(ROOT / 'dataset.py'), '--output', str(output)]
    for key in ('users', 'posts', 'follows', 'shares', 'feed_limit', 'seed', 'batch_size', 'reference_date'):
        command.extend(['--'+key.replace('_', '-'), str(config[key])])
    result = command_runner(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        raise RuntimeError(f"Geração do corpus falhou: {result.stderr.strip()}")
    return read_corpus_manifest(output, k6_config)


def file_sha256(path):
    value = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            value.update(block)
    return value.hexdigest()


def read_corpus_manifest(directory, k6_config):
    manifest_path = Path(directory) / 'manifest.json'
    manifest = json.loads(manifest_path.read_text())
    if manifest.get('schema_version') != 1:
        raise ValueError('Versão de manifesto do corpus incompatível.')
    if manifest.get('seed') != k6_config['seed'] or manifest.get('reference_date') != k6_config['reference_date']:
        raise ValueError('Seed ou reference_date do corpus não coincide com o k6.')
    if manifest['counts']['feed_items'] > manifest['spec']['feed_limit']:
        raise ValueError('Corpus excede o limite de entradas de feed.')
    for name, metadata in manifest['files'].items():
        path = Path(directory) / name
        if not path.is_file() or path.stat().st_size != metadata['bytes']:
            raise ValueError(f'Arquivo de corpus inválido: {name}.')
    return manifest


def print_terminal_summary(report):
    manifest = report['manifest']
    print(f"\n=== Desempenho {manifest['architecture']} | {report['measurement']['status']} ===")
    resources = manifest.get('environment', {}).get('resources')
    if resources:
        print(f"Recursos verificados: {resources['total_cpus']:.2f} CPU, "
              f"{resources['total_memory_bytes'] / 1_048_576:.0f} MiB limitados")
    if manifest.get('seed', {}).get('status') == 'succeeded':
        seed = manifest['seed']
        counts = seed['counts']
        print('Base: '+', '.join(f'{key}={value:,}' for key, value in counts.items()))
        print(f"Carga inicial: {seed['duration_ms'] / 1000:.2f}s")
    probe = report.get('fanout_probe', {})
    if probe.get('requested'):
        print(f"Fan-out hub: status={probe.get('status')}, HTTP={probe.get('http_status')}, "
              f"request={probe.get('request_latency_ms', 0):.2f}ms, "
              f"conclusão={probe.get('fanout_completion_ms', 0):.2f}ms, "
              f"feed={probe.get('observed_new_feed_items', 0):,}/"
              f"{probe.get('expected_new_feed_items', 0):,}")
    print(f"Duração de chegadas: {report['time']['arrival_duration_s']:.1f}s")
    print(f"{'Fluxo':<14} {'Rota':<22} {'Req':>9} {'Req/s':>9} {'p50 ms':>9} {'p95 ms':>9} "
          f"{'p99 ms':>9} {'Falhas':>8} {'Transp.':>8}")
    duration = max(report['time']['arrival_duration_s'], 1)
    for row in report['endpoints']:
        latency = row['latency_ms'] or {}
        count = latency.get('count', 0)
        transport = row['status_counts'].get('0', 0)
        print(f"{row['flow']:<14} {row['route']:<22} {count:>9,} {count / duration:>9.1f} "
              f"{latency.get('p50', 0):>9.2f} {latency.get('p95', 0):>9.2f} "
              f"{latency.get('p99', 0):>9.2f} {row['failed']:>8,} {transport:>8,}")
    print(f"Ações descartadas pelo gerador: {sum(x['dropped'] for x in report['actions'].values()):,}")
    for reason in report['measurement']['invalid_reasons']:
        print('Execução inválida: '+reason)


def load_config(path):
    config = json.loads(Path(path).read_text())
    defaults = json.loads((ROOT / 'config.json').read_text())
    if config.keys() != defaults.keys():
        raise ValueError('Configuração deve conter exatamente os campos de config.json.')
    if config['scenario'] not in ('reference', 'fanout', 'cascade', 'growth'):
        raise ValueError('Cenário inválido.')
    for key, value in config.items():
        if key in ('scenario', 'reference_date'):
            continue
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
            raise ValueError(f'{key}: número finito obrigatório.')
        if value < 0 or (value == 0 and key not in ('seed', 'share_fraction', 'post_fraction',
                                                   'recovery_seconds', 'graceful_stop_seconds')):
            raise ValueError(f'{key}: valor fora do intervalo.')
    for key in ('seed', 'users', 'preallocated_vus', 'max_vus', 'request_timeout_ms',
                'feed_poll_ms', 'feed_wait_ms', 'setup_wait_ms'):
        if not isinstance(config[key], int):
            raise ValueError(f'{key}: inteiro obrigatório.')
    if config['users'] < 2 or config['preallocated_vus'] > config['max_vus']:
        raise ValueError('Exigir users >= 2 e preallocated_vus <= max_vus.')
    if any(config[key] > 1 for key in ('share_fraction', 'post_fraction')):
        raise ValueError('Frações devem estar entre 0 e 1.')
    if config['graceful_stop_seconds'] > config['recovery_seconds']:
        raise ValueError('graceful_stop_seconds deve ser <= recovery_seconds.')
    if config['time_scale'] < .001:
        raise ValueError('time_scale deve ser >= 0.001.')
    datetime.strptime(config['reference_date'], '%Y-%m-%d')
    return config


def profile(config):
    def r(value):
        return max(1, math.floor(value * config['rate_scale'] + .5))
    s = config['time_scale']
    # Piecewise linear arrivals; zero-duration jumps preserve the original envelopes.
    return {flow: ([{'start_s': 0, 'end_s': 10*s, 'from': r(2), 'to': r(2)},
                    {'start_s': 10*s, 'end_s': 25*s, 'from': r(5), 'to': r(5)}]
                   if flow == 'social' else
                   [{'start_s': 0, 'end_s': 25*s, 'from': r(2), 'to': r(2)}]) +
                  [{'start_s': 25*s, 'end_s': 205*s, 'from': r(6), 'to': r(peak)}]
            for flow, peak in zip(FLOWS, (600, 100, 40))}


def stats(values):
    if not values:
        return None
    ordered = sorted(values)
    def percentile(q):
        index = (len(ordered) - 1) * q
        low = int(index)
        return ordered[low] + (ordered[math.ceil(index)] - ordered[low]) * (index - low)
    return {'count': len(values), 'min': ordered[0], 'max': ordered[-1],
            'avg': sum(values) / len(values), 'p50': percentile(.5),
            'p90': percentile(.9), 'p95': percentile(.95), 'p99': percentile(.99)}


def lines(path):
    if path.exists():
        with path.open() as stream:
            for number, line in enumerate(stream, 1):
                if line.strip():
                    try:
                        yield json.loads(line)
                    except json.JSONDecodeError as error:
                        raise ValueError(f'{path.name}:{number}: JSON inválido') from error


def epoch(value):
    return datetime.fromisoformat(value.replace('Z', '+00:00')).timestamp()


def build_report(directory, manifest, exit_code):
    config = manifest['config']
    summary_path = directory / 'summary.json'
    summary = json.loads(summary_path.read_text()) if summary_path.exists() else None
    events = [event for event in lines(directory / 'actions.jsonl')
              if event.get('kind') in ('prepared', 'audit', 'cut_observation') or
              (event.get('kind') == 'action_started' and event.get('index') == 0)]
    prepared = next((event for event in events if event['kind'] == 'prepared'), None)
    audits = [event for event in events if event['kind'] == 'audit']
    starts = [event['scenario_start_ms'] / 1000 for event in events
              if event['kind'] == 'action_started']
    origin = min(starts) if starts else None
    raw = directory / 'metrics.jsonl'
    buckets = defaultdict(lambda: {'latencies': [], 'requests': 0, 'failed': 0,
                                  'statuses': Counter(), 'actions_started': Counter(),
                                  'actions_completed': Counter(), 'dropped': Counter(),
                                  'vus': None, 'routes': defaultdict(list)})
    routes = defaultdict(lambda: {'latencies': [], 'statuses': Counter(), 'failed': 0,
                                 'error_codes': Counter()})
    counts = {name: Counter() for name in ('started', 'completed', 'dropped')}
    width = config['bucket_seconds']
    for event in lines(raw):
        if event.get('type') != 'Point':
            continue
        metric, data = event['metric'], event['data']
        tags = data.get('tags') or {}
        stamp, value = epoch(data['time']), data['value']
        offset = stamp - origin if origin is not None else None
        bucket = buckets[math.floor(max(0, offset) / width)] if offset is not None and offset >= -.01 else None
        if metric == 'request_latency' and tags.get('phase') in FLOWS:
            key = (tags['phase'], tags['route'])
            row = routes[key]
            row['latencies'].append(value)
            row['statuses'][tags['status']] += 1
            row['failed'] += tags['success'] != 'true'
            if tags.get('error_code') != '0':
                row['error_codes'][tags['error_code']] += 1
            if bucket is not None:
                bucket['requests'] += 1
                bucket['latencies'].append(value)
                bucket['statuses'][tags['status']] += 1
                bucket['failed'] += tags['success'] != 'true'
                bucket['routes']['|'.join(key)].append(value)
        elif metric in ('actions_started', 'actions_completed', 'dropped_iterations'):
            flow = tags.get('flow', tags.get('scenario'))
            if flow not in FLOWS:
                continue
            name = {'actions_started': 'started', 'actions_completed': 'completed',
                    'dropped_iterations': 'dropped'}[metric]
            counts[name][flow] += int(value)
            if bucket is not None:
                bucket[{'started': 'actions_started', 'completed': 'actions_completed',
                        'dropped': 'dropped'}[name]][flow] += int(value)
        elif metric == 'vus' and bucket is not None:
            bucket['vus'] = max(bucket['vus'] or 0, value)
    timeline = []
    for index in range(max(buckets, default=-1) + 1):
        row = buckets[index]
        timeline.append({'offset_s': index * width, 'window_s': width,
                         'requests': row['requests'], 'requests_per_second': row['requests'] / width,
                         'failed': row['failed'], 'status_counts': dict(row['statuses']),
                         'latency_ms': stats(row['latencies']),
                         'routes_latency_ms': {key: stats(values) for key, values in row['routes'].items()},
                         'actions_started': dict(row['actions_started']),
                         'actions_completed': dict(row['actions_completed']),
                         'dropped_iterations': dict(row['dropped']), 'vus_max': row['vus']})
    endpoint_stats = [{'flow': flow, 'route': route, 'latency_ms': stats(row['latencies']),
                       'status_counts': dict(row['statuses']), 'failed': row['failed'],
                       'error_codes': dict(row['error_codes'])}
                      for (flow, route), row in sorted(routes.items())]
    threshold_failures = []
    for name, metric in (summary or {}).get('metrics', {}).items():
        for expression, result in metric.get('thresholds', {}).items():
            if not result['ok']:
                threshold_failures.append(f'{name}: {expression}')
    reasons = []
    if not summary:
        reasons.append('Resumo k6 ausente; execução incompleta.')
    if not prepared:
        reasons.append('Preparação não concluída.')
    reset_failed = manifest.get('clean', {}).get('status') == 'failed'
    if reset_failed:
        reasons.append('Reset ou verificação de disponibilidade falhou; k6 não foi iniciado.')
    if manifest.get('clean', {}).get('requested') and not manifest.get('environment', {}).get('resource_limits_verified'):
        reasons.append('Limites de CPU/memória da composição não foram verificados.')
    seed_state = manifest.get('seed', {})
    if seed_state.get('requested') and seed_state.get('status') != 'succeeded':
        reasons.append('Carga ou validação do corpus falhou; k6 não foi iniciado.')
    fanout_state = manifest.get('fanout_probe', {})
    if fanout_state.get('requested') and fanout_state.get('status') != 'completed':
        reasons.append('Fan-out controlado do nó central não concluiu com HTTP 201.')
    if sum(counts['dropped'].values()):
        reasons.append('Gerador não iniciou todas as ações: dropped_iterations > 0.')
    if exit_code not in (0, 99) and not reset_failed:
        reasons.append(f'Falha de execução do k6: código {exit_code}.')
    baseline = prepared['baseline'] if prepared else None
    observed = {}
    for audit in audits:
        if audit['label'] in ('T1_observed', 'TA_observed', 'TF_observed'):
            values = audit['values']
            observed[audit['label']] = {'time_unix_ms': audit['time'], 'values': values,
                'delta_from_T0': {key: value - baseline[key] for key, value in values.items()}
                if values and baseline else None}
    report = {
        'schema_version': 1, 'manifest': manifest, 'k6_exit_code': exit_code,
        'measurement': {'status': 'invalid' if reasons else 'completed', 'invalid_reasons': reasons,
            'thresholds_passed': not threshold_failures if summary else None,
            'threshold_failures': threshold_failures,
            'official_comparison_ready': False,
            'pending': ['Preservação consistente de TF não implementada.',
                        'Auditoria de integridade e cobertura de destinatários não executada.',
                        'Recursos do gerador e limites efetivos do backend não verificados.']},
        'time': {'origin_unix_s': origin, 'origin_basis': 'k6_scenario_startTime',
                 'arrival_duration_s': 205 * config['time_scale'],
                 'recovery_seconds': config['recovery_seconds'],
                 'cuts': observed, 'TF_preserved': False},
        'prepared': prepared, 'fanout_probe': fanout_state, 'arrival_profile': profile(config),
        'actions': {flow: {'planned_integral': sum((segment['end_s'] - segment['start_s']) *
                        (segment['from'] + segment['to']) / 2 for segment in profile(config)[flow]),
                    'started': counts['started'][flow], 'completed': counts['completed'][flow],
                    'interrupted': counts['started'][flow] - counts['completed'][flow],
                    'dropped': counts['dropped'][flow]} for flow in FLOWS},
        'endpoints': endpoint_stats, 'timeline': timeline, 'k6_summary': summary,
        'infrastructure': {'status': 'not_measured', 'cpu': None, 'memory': None,
                           'disk': None, 'queue_backlog': None, 'traces': None},
        'artifacts': ['manifest.json', 'summary.json', 'metrics.jsonl', 'actions.jsonl', 'output.log'],
    }
    (directory / 'report.json').write_text(json.dumps(report, ensure_ascii=False, indent=2, allow_nan=False) + '\n')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True, help='URL do balanceador, sem caminhos de endpoint')
    parser.add_argument('--architecture', required=True, choices=('sync', 'async'))
    parser.add_argument('--clean', action='store_true',
                        help='recria somente os serviços e volumes isolados do benchmark')
    parser.add_argument('--seed-corpus', action='store_true',
                        help='gera e carrega o corpus de alto volume nesta execução')
    parser.add_argument('--corpus-dir', type=Path,
                        help='usa um corpus previamente gerado para uma comparação repetida')
    parser.add_argument('--dataset-config', type=Path, default=ROOT / 'dataset-config.json')
    parser.add_argument('--stop-after', action='store_true',
                        help='encerra e remove os volumes exclusivos do benchmark depois da execução')
    parser.add_argument('--fanout-wait-seconds', type=int, default=900,
                        help='limite para concluir a entrega do post de um hub (padrão: 900s)')
    parser.add_argument('--config', type=Path, default=ROOT / 'config.json')
    parser.add_argument('--output', type=Path, help='Diretório novo; execuções anteriores nunca são sobrescritas')
    args = parser.parse_args()
    seeded = args.seed_corpus or args.corpus_dir is not None
    if args.seed_corpus and args.corpus_dir is not None:
        parser.error('Use --seed-corpus ou --corpus-dir, não ambos.')
    if seeded and not args.clean:
        parser.error('A carga de corpus requer --clean para usar a composição isolada.')
    if args.stop_after and not args.clean:
        parser.error('--stop-after requer --clean.')
    if args.fanout_wait_seconds < 1:
        parser.error('--fanout-wait-seconds deve ser positivo.')
    from urllib.parse import urlsplit
    parsed = urlsplit(args.base_url)
    if parsed.scheme not in ('http', 'https') or not parsed.netloc or parsed.query or parsed.fragment:
        parser.error('--base-url deve ser uma URL HTTP(S) sem query ou fragmento.')
    if args.clean and parsed.hostname not in ('127.0.0.1', 'localhost', '::1'):
        parser.error('--clean só pode ser usado com uma URL local de loopback.')
    try:
        config = load_config(args.config)
    except (ValueError, OSError) as error:
        parser.error(str(error))
    directory = (args.output or ROOT / 'results' / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')).resolve()
    directory.mkdir(parents=True, exist_ok=False)
    (directory / 'config.json').write_text(json.dumps(config, indent=2))
    try:
        version = subprocess.check_output(['k6', 'version'], text=True).strip()
    except (OSError, subprocess.CalledProcessError) as error:
        parser.error(f'k6 não está disponível: {error}')
    commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    seed_report = None
    if args.corpus_dir is not None:
        corpus_dir = args.corpus_dir.resolve()
        try:
            seed_report = read_corpus_manifest(corpus_dir, config)
        except (OSError, ValueError, KeyError) as error:
            parser.error(f'Corpus inválido: {error}')
    elif args.seed_corpus:
        corpus_dir = directory / 'corpus'
        try:
            print('Gerando corpus de alto volume antes de iniciar os serviços...', flush=True)
            seed_report = generate_corpus(corpus_dir, args.dataset_config, config)
            print('Corpus gerado: '+', '.join(f'{key}={value:,}'
                  for key, value in seed_report['counts'].items()), flush=True)
        except (OSError, ValueError, RuntimeError, json.JSONDecodeError) as error:
            parser.error(f'Falha na geração do corpus: {error}')
    else:
        corpus_dir = directory / 'empty-corpus'
        corpus_dir.mkdir()
    if parsed.port is not None:
        benchmark_port = parsed.port
    else:
        benchmark_port = 19999 if args.architecture == 'async' else 19998
    manifest = {'created_at': datetime.now(timezone.utc).isoformat(), 'architecture': args.architecture,
                'base_url': args.base_url, 'config': config, 'git_commit': commit,
                'working_tree_dirty': bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT)),
                'k6_version': version,
                'script_sha256': hashlib.sha256((ROOT / 'stress.js').read_bytes()).hexdigest(),
                'dataset': ({'path': str(corpus_dir), 'profile': seed_report['profile'],
                             'seed': seed_report['seed'], 'counts': seed_report['counts'],
                             'manifest_sha256': file_sha256(corpus_dir / 'manifest.json')}
                            if seed_report else None),
                'clean': {'requested': args.clean, 'status': 'not_requested',
                          'commands': [command for command, _ in reset_commands(args.architecture)]
                          if args.clean else [],
                          'started_at': None, 'completed_at': None, 'error': None},
                'seed': {'requested': seeded, 'status': 'not_requested', 'counts': None,
                         'duration_ms': None, 'tables': None, 'error': None},
                'fanout_probe': {'requested': seeded, 'status': 'not_requested'},
                'shutdown': {'requested': args.stop_after, 'status': 'not_requested'},
                'environment': {'platform': sys.platform, 'backend_images': None,
                                'resource_limits_verified': False}}
    (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
    env = {**os.environ, 'CONFIG': str(directory / 'config.json'), 'BASE_URL': args.base_url,
           'SUMMARY_PATH': str(directory / 'summary.json'), 'CORPUS_DIR': str(corpus_dir),
           'BENCHMARK_PORT': str(benchmark_port), 'REFERENCE_DATE': config['reference_date'],
           'ARCHITECTURE': args.architecture,
           **RESOURCE_PROFILES[args.architecture]}
    command = ['k6', 'run', '--log-format', 'raw', '--console-output', str(directory / 'actions.jsonl'),
               '--out', f'json={directory / "metrics.jsonl"}', str(ROOT / 'stress.js')]
    with (directory / 'output.log').open('w') as output:
        if args.clean:
            manifest['clean']['status'] = 'running'
            manifest['clean']['started_at'] = datetime.now(timezone.utc).isoformat()
            (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
            try:
                clean_architecture(args.architecture, args.base_url, output, environment=env)
                manifest['clean']['status'] = 'succeeded'
            except (OSError, RuntimeError) as error:
                manifest['clean']['status'] = 'failed'
                manifest['clean']['error'] = str(error)
                print(f'Falha no reset: {error}', file=output, flush=True)
            manifest['clean']['completed_at'] = datetime.now(timezone.utc).isoformat()
            (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
        if args.clean and manifest['clean']['status'] != 'failed':
            try:
                manifest['environment']['resources'] = inspect_resource_limits(args.architecture)
                manifest['environment']['resource_limits_verified'] = True
            except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
                manifest['environment']['resource_error'] = str(error)
                print(f'Falha na verificação de recursos: {error}', file=output, flush=True)
            (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
        if seeded and manifest['clean']['status'] != 'failed' and manifest['environment']['resource_limits_verified']:
            manifest['seed']['status'] = 'running'
            manifest['seed']['started_at'] = datetime.now(timezone.utc).isoformat()
            (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
            try:
                loaded = load_corpus(args.architecture, env, output)
                metrics = validate_api_counts(args.base_url, seed_report['counts'])
                manifest['seed'].update({'status': 'succeeded', 'counts': loaded['counts'],
                                         'duration_ms': loaded['duration_ms'], 'tables': loaded['tables'],
                                         'metrics': metrics})
                manifest['fanout_probe']['status'] = 'running'
                manifest['fanout_probe']['started_at'] = datetime.now(timezone.utc).isoformat()
                (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
                probe = probe_hub_fanout(args.base_url, seed_report, args.fanout_wait_seconds)
                manifest['fanout_probe'].update(probe)
                manifest['fanout_probe']['completed_at'] = datetime.now(timezone.utc).isoformat()
                print(f"Fan-out hub: HTTP {probe['http_status']}, "
                      f"resposta {probe['request_latency_ms']:.2f}ms, "
                      f"conclusão {probe['fanout_completion_ms']:.2f}ms, "
                      f"feed +{probe['observed_new_feed_items']:,}/"
                      f"{probe['expected_new_feed_items']:,}.", flush=True)
            except (OSError, RuntimeError, ValueError, json.JSONDecodeError) as error:
                manifest['seed']['status'] = 'failed'
                manifest['seed']['error'] = str(error)
                print(f'Falha na carga do corpus: {error}', file=output, flush=True)
            manifest['seed']['completed_at'] = datetime.now(timezone.utc).isoformat()
            (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
        if (manifest['clean']['status'] == 'failed'
                or manifest['clean']['requested'] and not manifest['environment']['resource_limits_verified']
                or manifest['seed']['status'] == 'failed'
                or manifest['fanout_probe'].get('requested')
                and manifest['fanout_probe'].get('status') != 'completed'):
            result = subprocess.CompletedProcess(command, 1)
        else:
            print(f'Executando {config["scenario"]} em {args.base_url}. Artefatos: {directory}', flush=True)
            result = subprocess.run(command, env=env, stdout=output, stderr=subprocess.STDOUT)
    report = build_report(directory, manifest, result.returncode)
    print_terminal_summary(report)
    print(f'Relatório: {directory / "report.json"} ({report["measurement"]["status"]})')
    if args.stop_after:
        compose, backend = compose_command(args.architecture)
        shutdown = compose + ['down', '--volumes', '--remove-orphans']
        try:
            stopped = subprocess.run(shutdown, cwd=backend, env=env, text=True,
                                     stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            manifest['shutdown']['status'] = 'succeeded' if stopped.returncode == 0 else 'failed'
            if stopped.stdout:
                print(stopped.stdout, end='')
        except OSError as error:
            manifest['shutdown']['status'] = 'failed'
            print(f'Falha ao encerrar ambiente de benchmark: {error}')
        (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
    return result.returncode


if __name__ == '__main__':
    sys.exit(main())
