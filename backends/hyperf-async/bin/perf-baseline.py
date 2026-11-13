#!/usr/bin/env python3
"""Medição de desempenho comparável entre mudanças do backend assíncrono.

Executa code/k6/run.py com --clean --stop-after no projeto Compose isolado
tcc-benchmark-async (volumes próprios, porta 19999), nunca no projeto
tcc-hyperf-async. Durante a execução amostra `docker stats` e a profundidade
das filas e grava um resumo JSON por cenário em <saida>/summary.json.
"""
import argparse
from datetime import datetime, timezone
import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys
import threading
import time

ROOT = Path(__file__).resolve().parents[1]
REPO = ROOT.parents[1]
RUNNER = REPO / 'code' / 'k6' / 'run.py'
CONFIGS = Path(__file__).resolve().parent / 'perf'
PROJECT = 'tcc-benchmark-async'  # Definido em code/k6/run.py; distinto de tcc-hyperf-async.
LIMITS = {'nginx': .10, 'api1': .20, 'api2': .20, 'worker': .30, 'rabbitmq': .20, 'neo4j': .50}
QUEUES = ('posts', 'distribution')
SATURATION = .9  # Amostra conta como saturada a partir de 90% do limite de CPU.

_UNITS = {'b': 1 / 1048576, 'kib': 1 / 1024, 'kb': 1 / 1048.576, 'mib': 1, 'mb': 1 / 1.048576,
          'gib': 1024, 'gb': 1000 / 1.048576}


def parse_cpu(value):
    """'20.05%' -> 0.2005 núcleos (docker stats usa 100% = 1 CPU)."""
    return float(value.strip().rstrip('%')) / 100


def parse_mib(value):
    """'123.4MiB / 320MiB' -> 123.4 (uso, em MiB)."""
    match = re.match(r'\s*([\d.]+)\s*([a-zA-Z]+)', value.split('/')[0])
    if not match:
        raise ValueError(f'Memória não reconhecida: {value!r}')
    return float(match[1]) * _UNITS[match[2].lower()]


def service_name(container):
    """'tcc-benchmark-async-api1-1' -> 'api1'."""
    prefix = PROJECT + '-'
    name = container[len(prefix):] if container.startswith(prefix) else container
    return re.sub(r'-\d+$', '', name)


def parse_stats_line(line, stamp):
    row = json.loads(line)
    return {'time': stamp, 'service': service_name(row['Name']),
            'cpu': parse_cpu(row['CPUPerc']), 'mem_mib': parse_mib(row['MemUsage'])}


def parse_queue_output(text, stamp):
    """Saída de `rabbitmqctl list_queues -q --formatter json name messages`."""
    try:
        rows = json.loads(text)
    except json.JSONDecodeError:
        return []
    return [{'time': stamp, 'queue': row['name'], 'messages': int(row['messages'])}
            for row in rows if row.get('name') in QUEUES]


def percentile(values, q):
    ordered = sorted(values)
    index = (len(ordered) - 1) * q
    low = int(index)
    return ordered[low] + (ordered[math.ceil(index)] - ordered[low]) * (index - low)


def in_window(samples, window):
    start, end = window
    return [sample for sample in samples if start <= sample['time'] <= end]


def aggregate_containers(samples, window, limits=LIMITS):
    result = {}
    for service in sorted({sample['service'] for sample in samples}):
        rows = [s for s in in_window(samples, window) if s['service'] == service]
        if not rows:
            continue
        cpu = [row['cpu'] for row in rows]
        limit = limits.get(service)
        result[service] = {
            'samples': len(rows), 'cpu_limit': limit,
            'cpu_avg': round(sum(cpu) / len(cpu), 4), 'cpu_p95': round(percentile(cpu, .95), 4),
            'cpu_max': round(max(cpu), 4),
            'cpu_avg_of_limit': round(sum(cpu) / len(cpu) / limit, 3) if limit else None,
            'saturated_fraction': round(sum(c >= limit * SATURATION for c in cpu) / len(cpu), 3)
            if limit else None,
            'mem_max_mib': round(max(row['mem_mib'] for row in rows), 1),
        }
    return result


def aggregate_queues(samples, window):
    result = {}
    for queue in QUEUES:
        values = [s['messages'] for s in in_window(samples, window) if s['queue'] == queue]
        result[queue] = ({'samples': len(values), 'max': max(values), 'last': values[-1],
                          'avg': round(sum(values) / len(values), 1)} if values else None)
    return result


def summarize_endpoints(report):
    duration = max(report['time']['arrival_duration_s'], 1)
    endpoints = {}
    total = 0
    for row in report['endpoints']:
        latency = row['latency_ms'] or {}
        count = latency.get('count', 0)
        total += count
        endpoints[f"{row['flow']}|{row['route']}"] = {
            'count': count, 'rps': round(count / duration, 2),
            'p50_ms': round(latency.get('p50', 0), 2), 'p95_ms': round(latency.get('p95', 0), 2),
            'p99_ms': round(latency.get('p99', 0), 2), 'failed': row['failed'],
            'status_counts': row['status_counts'],
        }
    # Em stress.js, posts_get só ocorre no GET único 1–30 ms após cada 201 de POST /posts.
    reads = endpoints.get('social|posts_get')
    post_get = None
    if reads and reads['count']:
        ok = reads['status_counts'].get('200', 0)
        post_get = {'count': reads['count'], 'status_200': ok, 'ratio_200': round(ok / reads['count'], 4)}
    return {'total_requests': total, 'total_rps': round(total / duration, 2),
            'endpoints': endpoints, 'post_get_after_create': post_get}


def load_window(report):
    """Janela das chegadas mais a recuperação, em epoch s; None se o k6 não iniciou."""
    origin = report['time']['origin_unix_s']
    if origin is None:
        return None
    return origin, origin + report['time']['arrival_duration_s'] + report['time']['recovery_seconds']


def bottleneck(containers):
    ranked = sorted(((v['cpu_avg_of_limit'], k) for k, v in containers.items()
                     if v['cpu_avg_of_limit'] is not None), reverse=True)
    return [{'service': k, 'cpu_avg_of_limit': v} for v, k in ranked]


def summarize(report, stats, queues):
    window = load_window(report)
    summary = {'status': report['measurement']['status'],
               'invalid_reasons': report['measurement']['invalid_reasons'],
               'dropped_iterations': sum(a['dropped'] for a in report['actions'].values()),
               'window_unix_s': window, **summarize_endpoints(report)}
    if window:
        summary['containers'] = aggregate_containers(stats, window)
        summary['cpu_ranking'] = bottleneck(summary['containers'])
        summary['queues'] = aggregate_queues(queues, window)
    return summary


class Sampler:
    """`docker stats` e filas em threads separadas: rabbitmqctl leva segundos e não pode atrasar a CPU."""

    def __init__(self, queue_every):
        self.stop = threading.Event()
        self.stats, self.queues, self.errors = [], [], []
        self.queue_every = queue_every
        self.threads = [threading.Thread(target=self._stats, daemon=True)]
        if queue_every:
            self.threads.append(threading.Thread(target=self._queues, daemon=True))

    def start(self):
        for thread in self.threads:
            thread.start()

    def join(self):
        for thread in self.threads:
            thread.join()

    def _ids(self):
        return subprocess.run(['docker', 'ps', '-q', '--filter', f'label=com.docker.compose.project={PROJECT}'],
                              capture_output=True, text=True).stdout.split()

    def _stats(self):
        while not self.stop.is_set():
            started = time.time()
            ids = self._ids()
            if ids:
                out = subprocess.run(['docker', 'stats', '--no-stream', '--format', '{{json .}}', *ids],
                                     capture_output=True, text=True)
                for line in out.stdout.splitlines():
                    try:
                        self.stats.append(parse_stats_line(line, started))
                    except (ValueError, KeyError) as error:
                        self.errors.append(str(error))
            self.stop.wait(max(0.0, 1.0 - (time.time() - started)))

    def _queues(self):
        while not self.stop.is_set():
            started = time.time()
            if self._ids():
                # Somente leitura; executa no cgroup do RabbitMQ, por isso tem intervalo próprio.
                out = subprocess.run(['docker', 'compose', '-p', PROJECT, 'exec', '-T', 'rabbitmq',
                                      'rabbitmqctl', 'list_queues', '-q', '--formatter', 'json',
                                      'name', 'messages'], capture_output=True, text=True)
                self.queues.extend(parse_queue_output(out.stdout, started))
            self.stop.wait(max(0.0, self.queue_every - (time.time() - started)))


K6_ENV = ('CONFIG', 'BASE_URL', 'SUMMARY_PATH', 'CORPUS_DIR', 'BENCHMARK_PORT', 'REFERENCE_DATE')


def k6_docker_wrapper(directory, image):
    """Cria um executável `k6` que roda a imagem oficial com rede do host e o repositório montado."""
    directory.mkdir(parents=True, exist_ok=True)
    env = ' '.join(f'-e {name}' for name in K6_ENV)
    script = directory / 'k6'
    script.write_text('#!/bin/sh\n'
                      f'exec docker run --rm -i --network host -u "$(id -u):$(id -g)" {env} '
                      f'-v "{REPO}:{REPO}" -w "$PWD" {image} "$@"\n')
    script.chmod(0o755)
    return directory


def run_scenario(name, config, output, queue_every, env=None):
    sampler = Sampler(queue_every)
    sampler.start()
    command = [sys.executable, str(RUNNER), '--base-url', 'http://127.0.0.1:19999', '--architecture', 'async',
               '--clean', '--stop-after', '--config', str(config), '--output', str(output)]
    print('$ '+' '.join(command), flush=True)
    try:
        code = subprocess.run(command, cwd=REPO, env=env).returncode
    finally:
        sampler.stop.set()
        sampler.join()
    report = json.loads((output / 'report.json').read_text())
    summary = summarize(report, sampler.stats, sampler.queues)
    summary.update({'scenario': name, 'runner_exit_code': code, 'sampler_errors': sampler.errors[:20],
                    'queue_sample_interval_s': queue_every})
    (output / 'samples.json').write_text(json.dumps({'stats': sampler.stats, 'queues': sampler.queues}))
    return summary


def print_summary(summary):
    print(f"\n== {summary['scenario']} | {summary['status']} | {summary['total_rps']} req/s ==")
    pg = summary['post_get_after_create']
    if pg:
        print(f"GET pós-criação 200: {pg['status_200']}/{pg['count']} ({pg['ratio_200']:.2%})")
    for key, row in summary['endpoints'].items():
        print(f"  {key:<28} {row['rps']:>8} req/s p50 {row['p50_ms']:>8} p95 {row['p95_ms']:>8} "
              f"p99 {row['p99_ms']:>8} falhas {row['failed']}")
    for service, row in summary.get('containers', {}).items():
        print(f"  {service:<9} CPU média {row['cpu_avg']:.3f}/{row['cpu_limit']} "
              f"({row['cpu_avg_of_limit']:.0%}), saturada {row['saturated_fraction']:.0%}, "
              f"mem máx {row['mem_max_mib']} MiB")
    for queue, row in (summary.get('queues') or {}).items():
        print(f"  fila {queue}: {row}")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--scenario', action='append', choices=('reference', 'fanout'),
                        help='repetível; padrão: reference e fanout')
    parser.add_argument('--label', default='baseline', help='nome da medição (ex.: task2-hooks)')
    parser.add_argument('--output', type=Path, help='diretório novo; padrão backends/hyperf-async/runtime/perf/')
    parser.add_argument('--queue-interval', type=float, default=5.0,
                        help='segundos entre leituras de fila; 0 desativa (padrão 5)')
    parser.add_argument('--k6-docker', metavar='IMAGEM',
                        help='usa k6 via Docker (ex.: grafana/k6:2.3.0) quando não está instalado no host')
    args = parser.parse_args()
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    output = (args.output or ROOT / 'runtime' / 'perf' / f'{stamp}-{args.label}').resolve()
    output.mkdir(parents=True, exist_ok=False)
    env = None
    if args.k6_docker:
        wrapper = k6_docker_wrapper(output / '.k6', args.k6_docker)
        env = {**os.environ, 'PATH': f"{wrapper}{os.pathsep}{os.environ.get('PATH', '')}"}
    summaries = []
    for name in args.scenario or ['reference', 'fanout']:
        summary = run_scenario(name, CONFIGS / f'{name}.json', output / name, args.queue_interval, env)
        print_summary(summary)
        summaries.append(summary)
    result = {'label': args.label, 'created_at': stamp, 'scenarios': summaries}
    (output / 'summary.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(f'\nResumo: {output / "summary.json"}')
    return 0 if all(s['status'] == 'completed' for s in summaries) else 1


if __name__ == '__main__':
    sys.exit(main())
