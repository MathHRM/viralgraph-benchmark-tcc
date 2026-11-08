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

ROOT = Path(__file__).resolve().parent
FLOWS = ('social', 'validSearch', 'invalidSearch')


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
    if sum(counts['dropped'].values()):
        reasons.append('Gerador não iniciou todas as ações: dropped_iterations > 0.')
    if exit_code not in (0, 99):
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
        'prepared': prepared, 'arrival_profile': profile(config),
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
    parser.add_argument('--config', type=Path, default=ROOT / 'config.json')
    parser.add_argument('--output', type=Path, help='Diretório novo; execuções anteriores nunca são sobrescritas')
    args = parser.parse_args()
    from urllib.parse import urlsplit
    parsed = urlsplit(args.base_url)
    if parsed.scheme not in ('http', 'https') or not parsed.netloc or parsed.query or parsed.fragment:
        parser.error('--base-url deve ser uma URL HTTP(S) sem query ou fragmento.')
    try:
        config = load_config(args.config)
    except (ValueError, OSError) as error:
        parser.error(str(error))
    directory = (args.output or ROOT / 'results' / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')).resolve()
    directory.mkdir(parents=True, exist_ok=False)
    (directory / 'config.json').write_text(json.dumps(config, indent=2))
    version = subprocess.check_output(['k6', 'version'], text=True).strip()
    commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    manifest = {'created_at': datetime.now(timezone.utc).isoformat(), 'architecture': args.architecture,
                'base_url': args.base_url, 'config': config, 'git_commit': commit,
                'working_tree_dirty': bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT)),
                'k6_version': version,
                'script_sha256': hashlib.sha256((ROOT / 'stress.js').read_bytes()).hexdigest(),
                'environment': {'platform': sys.platform, 'backend_images': None,
                                'resource_limits_verified': False}}
    (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
    env = {**os.environ, 'CONFIG': str(directory / 'config.json'), 'BASE_URL': args.base_url,
           'SUMMARY_PATH': str(directory / 'summary.json')}
    command = ['k6', 'run', '--log-format', 'raw', '--console-output', str(directory / 'actions.jsonl'),
               '--out', f'json={directory / "metrics.jsonl"}', str(ROOT / 'stress.js')]
    print(f'Executando {config["scenario"]} em {args.base_url}. Artefatos: {directory}', flush=True)
    with (directory / 'output.log').open('w') as output:
        result = subprocess.run(command, env=env, stdout=output, stderr=subprocess.STDOUT)
    report = build_report(directory, manifest, result.returncode)
    print(f'Relatório: {directory / "report.json"} ({report["measurement"]["status"]})')
    return result.returncode


if __name__ == '__main__':
    sys.exit(main())
