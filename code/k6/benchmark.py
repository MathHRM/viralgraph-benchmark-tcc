#!/usr/bin/env python3
"""Executa execuções pareadas Laravel/Hyperf e imprime a comparação de desempenho."""
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import shutil
import statistics
import subprocess
import sys

import run as runner

ROOT = Path(__file__).resolve().parent
ARCHITECTURES = ('sync', 'async')
PORTS = {'sync': 19998, 'async': 19999}


def collect_runs(reports):
    values = {}
    for report in reports:
        if report is None:
            continue
        duration = max(float(report['time']['arrival_duration_s']), 1.0)
        for endpoint in report['endpoints']:
            latency = endpoint.get('latency_ms')
            if not latency:
                continue
            key = (endpoint['flow'], endpoint['route'])
            values.setdefault(key, []).append({
                'count': latency['count'],
                'rps': latency['count'] / duration,
                'p50': latency['p50'], 'p95': latency['p95'], 'p99': latency['p99'],
                'failed': endpoint['failed'],
            })
    return values


def median(values, key):
    samples = [run[key] for run in values]
    return statistics.median(samples) if samples else None


def comparison(reports):
    result = {}
    route_values = {}
    for architecture in ARCHITECTURES:
        selected = [row for row in reports if row['architecture'] == architecture]
        valid = [row for row in selected if row['valid'] and row['report']]
        result[architecture] = {
            'runs': len(selected),
            'valid_runs': sum(row['valid'] for row in selected),
        }
        route_values[architecture] = collect_runs([row['report'] for row in valid])
    result['route_comparison'] = []
    keys = set(route_values['sync']) | set(route_values['async'])
    for flow, route in sorted(keys):
        sync_values = route_values['sync'].get((flow, route), [])
        async_values = route_values['async'].get((flow, route), [])
        sync_p95 = median(sync_values, 'p95')
        async_p95 = median(async_values, 'p95')
        sync_rps = median(sync_values, 'rps')
        async_rps = median(async_values, 'rps')
        result['route_comparison'].append({
            'flow': flow, 'route': route,
            'sync': {'runs': len(sync_values), 'rps': sync_rps, 'p50_ms': median(sync_values, 'p50'),
                     'p95_ms': sync_p95, 'p99_ms': median(sync_values, 'p99'),
                     'failed': median(sync_values, 'failed')},
            'async': {'runs': len(async_values), 'rps': async_rps, 'p50_ms': median(async_values, 'p50'),
                      'p95_ms': async_p95, 'p99_ms': median(async_values, 'p99'),
                      'failed': median(async_values, 'failed')},
            'p95_delta_percent_async_vs_sync': (
                (async_p95 - sync_p95) / sync_p95 * 100 if sync_p95 else None),
            'throughput_delta_percent_async_vs_sync': (
                (async_rps - sync_rps) / sync_rps * 100 if sync_rps else None),
        })
    return result


def display(result, repetitions):
    print('\n========== COMPARAÇÃO DE DESEMPENHO ==========')
    for architecture in ARCHITECTURES:
        state = result[architecture]
        print(f"{architecture}: {state['valid_runs']}/{state['runs']} execuções válidas "
              f'(solicitadas: {repetitions})')
    print(f"{'Fluxo':<14} {'Rota':<22} {'sync p95':>12} {'async p95':>12} "
          f"{'Δ p95':>10} {'sync req/s':>12} {'async req/s':>12} {'Δ req/s':>10}")
    for row in result['route_comparison']:
        def fmt(value):
            return 'n/d' if value is None else f'{value:.2f}'
        delta_p95 = row['p95_delta_percent_async_vs_sync']
        delta_rps = row['throughput_delta_percent_async_vs_sync']
        print(f"{row['flow']:<14} {row['route']:<22} {fmt(row['sync']['p95_ms']):>12} "
              f"{fmt(row['async']['p95_ms']):>12} {fmt(delta_p95):>9}% "
              f"{fmt(row['sync']['rps']):>12} {fmt(row['async']['rps']):>12} {fmt(delta_rps):>9}%")
    if any(state['valid_runs'] != repetitions for state in (result['sync'], result['async'])):
        print('Atenção: alguma execução foi inválida; não trate a comparação como resultado conclusivo.')


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repetitions', type=int, default=3,
                        help='repetições por arquitetura; alterna a ordem a cada repetição (padrão: 3)')
    parser.add_argument('--config', type=Path, default=ROOT / 'config.json')
    parser.add_argument('--dataset-config', type=Path, default=ROOT / 'dataset-config.json')
    parser.add_argument('--output', type=Path, help='diretório novo para corpus e execuções')
    args = parser.parse_args(argv)
    if args.repetitions < 1 or args.repetitions > 10:
        parser.error('--repetitions deve estar entre 1 e 10.')
    for required in ('docker', 'k6'):
        if shutil.which(required) is None:
            parser.error(f'{required} não encontrado no PATH.')
    try:
        subprocess.run(['docker', 'info'], check=True, stdout=subprocess.DEVNULL,
                       stderr=subprocess.PIPE, text=True)
        k6_version = subprocess.check_output(['k6', 'version'], text=True).strip()
        config = runner.load_config(args.config)
    except (OSError, subprocess.CalledProcessError, ValueError) as error:
        parser.error(f'Pré-requisito indisponível: {error}')

    directory = (args.output or ROOT / 'results' /
                 ('comparison-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ'))).resolve()
    directory.mkdir(parents=True, exist_ok=False)
    corpus_dir = directory / 'corpus'
    print(f'Gerando corpus determinístico; k6={k6_version}. Saída: {directory}', flush=True)
    try:
        manifest = runner.generate_corpus(corpus_dir, args.dataset_config, config)
    except (OSError, RuntimeError, ValueError, json.JSONDecodeError) as error:
        parser.error(f'Falha ao gerar corpus: {error}')
    print('Corpus pronto: '+', '.join(f'{key}={value:,}'
          for key, value in manifest['counts'].items()), flush=True)

    rows = []
    for repetition in range(args.repetitions):
        order = ARCHITECTURES if repetition % 2 == 0 else tuple(reversed(ARCHITECTURES))
        for architecture in order:
            base_url = f'http://127.0.0.1:{PORTS[architecture]}'
            run_dir = directory / 'runs' / f'{repetition + 1:02d}-{architecture}'
            run_dir.parent.mkdir(parents=True, exist_ok=True)
            command = [sys.executable, str(ROOT / 'run.py'), '--base-url', base_url,
                       '--architecture', architecture, '--clean', '--corpus-dir', str(corpus_dir),
                       '--config', str(args.config), '--output', str(run_dir), '--stop-after']
            print(f'\nRepetição {repetition + 1}/{args.repetitions}: {architecture}', flush=True)
            completed = subprocess.run(command, cwd=ROOT)
            report_path = run_dir / 'report.json'
            report = json.loads(report_path.read_text()) if report_path.is_file() else None
            valid = bool(report and report['measurement']['status'] == 'completed'
                         and report['measurement']['thresholds_passed'] is True
                         and not any(item['dropped'] for item in report['actions'].values())
                         and report['manifest'].get('seed', {}).get('status') == 'succeeded'
                         and report['manifest'].get('fanout_probe', {}).get('status') == 'completed'
                         and report['manifest'].get('environment', {}).get('resource_limits_verified') is True)
            rows.append({'repetition': repetition + 1, 'architecture': architecture,
                         'exit_code': completed.returncode, 'valid': valid,
                         'report_path': str(report_path), 'report': report})

    result = comparison(rows)
    result['corpus'] = {'profile': manifest['profile'], 'seed': manifest['seed'],
                        'reference_date': manifest['reference_date'], 'counts': manifest['counts'],
                        'feed_limit': manifest['spec']['feed_limit']}
    result['repetitions_per_architecture'] = args.repetitions
    (directory / 'comparison.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    display(result, args.repetitions)
    print(f'\nComparação JSON: {directory / "comparison.json"}')
    return 0 if all(result[arch]['valid_runs'] == args.repetitions for arch in ARCHITECTURES) else 1


if __name__ == '__main__':
    sys.exit(main())
