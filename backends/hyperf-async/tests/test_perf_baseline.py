"""Unitário da agregação de bin/perf-baseline.py; não executa Docker nem k6."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location(
    'perf_baseline', Path(__file__).resolve().parents[1] / 'bin' / 'perf-baseline.py')
perf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(perf)


def report(origin=100.0, endpoints=None):
    return {
        'time': {'origin_unix_s': origin, 'arrival_duration_s': 10, 'recovery_seconds': 5},
        'measurement': {'status': 'completed', 'invalid_reasons': []},
        'actions': {'social': {'dropped': 2}, 'validSearch': {'dropped': 0}},
        'endpoints': endpoints if endpoints is not None else [
            {'flow': 'social', 'route': 'posts_create', 'failed': 1,
             'latency_ms': {'count': 40, 'p50': 3.0, 'p95': 9.5, 'p99': 20.0},
             'status_counts': {'201': 39, '503': 1}},
            {'flow': 'social', 'route': 'posts_get', 'failed': 3,
             'latency_ms': {'count': 39, 'p50': 2.0, 'p95': 4.0, 'p99': 5.0},
             'status_counts': {'200': 36, '404': 3}},
        ],
    }


class ParseTest(unittest.TestCase):
    def test_cpu_percent_is_cores(self):
        self.assertAlmostEqual(perf.parse_cpu('20.05%'), .2005)
        self.assertAlmostEqual(perf.parse_cpu('0.00%'), 0)

    def test_memory_units(self):
        self.assertAlmostEqual(perf.parse_mib('123.4MiB / 320MiB'), 123.4)
        self.assertAlmostEqual(perf.parse_mib('1.5GiB / 2GiB'), 1536)
        self.assertAlmostEqual(perf.parse_mib('512KiB / 64MiB'), .5)
        with self.assertRaises(ValueError):
            perf.parse_mib('-- / --')

    def test_service_name(self):
        self.assertEqual(perf.service_name('tcc-benchmark-async-api1-1'), 'api1')
        self.assertEqual(perf.service_name('tcc-benchmark-async-rabbitmq-1'), 'rabbitmq')

    def test_stats_line(self):
        line = '{"Name":"tcc-benchmark-async-neo4j-1","CPUPerc":"50.00%","MemUsage":"900MiB / 1.367GiB"}'
        self.assertEqual(perf.parse_stats_line(line, 7.0),
                         {'time': 7.0, 'service': 'neo4j', 'cpu': .5, 'mem_mib': 900.0})

    def test_queue_output_filters_and_tolerates_garbage(self):
        text = '[{"name":"posts","messages":12},{"name":"other","messages":1},{"name":"distribution","messages":0}]'
        self.assertEqual(perf.parse_queue_output(text, 1.0), [
            {'time': 1.0, 'queue': 'posts', 'messages': 12},
            {'time': 1.0, 'queue': 'distribution', 'messages': 0}])
        self.assertEqual(perf.parse_queue_output('Error: node down', 1.0), [])


class AggregateTest(unittest.TestCase):
    def test_containers_use_only_window_and_limits(self):
        samples = [{'time': t, 'service': 'neo4j', 'cpu': c, 'mem_mib': m}
                   for t, c, m in ((90, 9.0, 999), (100, .5, 800), (105, .3, 900), (110, .46, 850))]
        samples.append({'time': 101, 'service': 'api1', 'cpu': .1, 'mem_mib': 50})
        result = perf.aggregate_containers(samples, (100, 115))
        neo = result['neo4j']
        self.assertEqual(neo['samples'], 3)
        self.assertAlmostEqual(neo['cpu_avg'], .42)
        self.assertEqual(neo['cpu_max'], .5)
        self.assertAlmostEqual(neo['cpu_avg_of_limit'], .84)
        self.assertAlmostEqual(neo['saturated_fraction'], .667)  # .5 e .46 >= 0.45
        self.assertEqual(neo['mem_max_mib'], 900)
        self.assertAlmostEqual(result['api1']['cpu_avg_of_limit'], .5)
        self.assertEqual([r['service'] for r in perf.bottleneck(result)], ['neo4j', 'api1'])

    def test_unknown_service_has_no_ratio(self):
        result = perf.aggregate_containers([{'time': 1, 'service': 'x', 'cpu': .1, 'mem_mib': 1}], (0, 2))
        self.assertIsNone(result['x']['cpu_avg_of_limit'])
        self.assertEqual(perf.bottleneck(result), [])

    def test_queues(self):
        samples = [{'time': t, 'queue': 'posts', 'messages': n} for t, n in ((1, 50), (2, 4), (3, 10))]
        result = perf.aggregate_queues(samples, (2, 3))
        self.assertEqual(result['posts'], {'samples': 2, 'max': 10, 'last': 10, 'avg': 7.0})
        self.assertIsNone(result['distribution'])

    def test_endpoints_and_post_get_ratio(self):
        result = perf.summarize_endpoints(report())
        self.assertEqual(result['total_requests'], 79)
        self.assertEqual(result['total_rps'], 7.9)
        self.assertEqual(result['endpoints']['social|posts_create']['p95_ms'], 9.5)
        self.assertEqual(result['post_get_after_create'], {'count': 39, 'status_200': 36, 'ratio_200': .9231})

    def test_post_get_absent(self):
        self.assertIsNone(perf.summarize_endpoints(report(endpoints=[]))['post_get_after_create'])

    def test_summarize_window(self):
        stats = [{'time': 112, 'service': 'api2', 'cpu': .2, 'mem_mib': 70}]
        queues = [{'time': 116, 'queue': 'posts', 'messages': 3}]  # fora de 100..115
        result = perf.summarize(report(), stats, queues)
        self.assertEqual(result['window_unix_s'], (100.0, 115.0))
        self.assertEqual(result['dropped_iterations'], 2)
        self.assertEqual(result['containers']['api2']['saturated_fraction'], 1.0)
        self.assertIsNone(result['queues']['posts'])

    def test_summarize_without_k6_start(self):
        result = perf.summarize(report(origin=None), [], [])
        self.assertIsNone(result['window_unix_s'])
        self.assertNotIn('containers', result)


class WrapperTest(unittest.TestCase):
    def test_k6_docker_wrapper(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            directory = perf.k6_docker_wrapper(Path(tmp) / 'bin', 'grafana/k6:2.3.0')
            script = directory / 'k6'
            text = script.read_text()
            self.assertTrue(script.stat().st_mode & 0o100)
            self.assertIn('--network host', text)
            self.assertIn(f'-v "{perf.REPO}:{perf.REPO}"', text)
            for name in perf.K6_ENV:
                self.assertIn(f'-e {name}', text)
            self.assertTrue(text.rstrip().endswith('grafana/k6:2.3.0 "$@"'))


if __name__ == '__main__':
    unittest.main()
