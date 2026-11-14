"""Testes do gerador; a API em memória é uma fixture, não uma arquitetura do TCC."""
import importlib.util
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import os
from pathlib import Path
import subprocess
import tempfile
import threading
import unittest
from unittest import mock
import uuid

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('runner', ROOT / 'run.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class Fixture(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def reply(self, status, data, location=None):
        body = json.dumps(data).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        if location:
            self.send_header('Location', location)
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        with self.server.lock:
            state = self.server.state
            path = self.path.split('?')[0]
            if path == '/metricas':
                values = dict(usuarios=len(state['users']), feeds=len(state['users']),
                              relacoes_seguir=len(state['edges']), posts_criados=len(state['posts']),
                              compartilhamentos=len(state['shares']),
                              entradas_feed=sum(map(len, state['feeds'].values())))
                values['inserts_logicos'] = sum(values.values())
                return self.reply(200, values)
            if path.startswith('/usuarios/'):
                user = state['users'].get(path.split('/')[-1])
                return self.reply(200 if user else 404, user)
            if path.startswith('/posts/'):
                post = state['posts'].get(path.split('/')[-1])
                if post and self.server.broken:
                    post = {**post, 'conteudo': 'conteúdo incorreto'}
                return self.reply(200 if post else 404, post)
            if path.startswith('/feed/'):
                user = path.split('/')[-1]
                return self.reply(200, {'data': list(state['feeds'][user].values()), 'proximo_cursor': None})
            if path in ('/usuarios', '/posts'):
                if '?' not in self.path:
                    return self.reply(422, {'erro': {'codigo': 'validacao', 'mensagem': 'Termo ausente'}})
                return self.reply(200, {'data': [], 'proximo_cursor': None})
            self.reply(404, {})

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        with self.server.lock:
            state = self.server.state
            identifier = str(uuid.uuid4())
            data = {'id': identifier, **body, 'criado_em': '2026-11-08T00:00:00Z'}
            location = None
            if self.path == '/usuarios':
                state['users'][identifier] = data
                state['feeds'][identifier] = {}
                location = f'/usuarios/{identifier}'
            elif self.path == '/seguir-usuario':
                pair = (body['seguidor_id'], body['seguido_id'])
                if pair in state['edges']:
                    return self.reply(422, {})
                state['edges'].add(pair)
            elif self.path == '/posts':
                state['posts'][identifier] = data
                self.deliver(body['usuario_id'], data, 'publicacao', identifier)
                location = f'/posts/{identifier}'
            elif self.path.startswith('/posts/') and self.path.endswith('/share'):
                body['post_id'] = self.path.split('/')[2]
                pair = (body['usuario_id'], body['post_id'])
                if pair in state['shares'] or body['post_id'] not in state['feeds'][body['usuario_id']]:
                    return self.reply(422, {})
                state['shares'].add(pair)
                self.deliver(body['usuario_id'], state['posts'][body['post_id']], 'compartilhamento', identifier)
            else:
                return self.reply(404, {})
            self.reply(201, 'corpo livre' if self.path == '/posts' else data, location)

    def deliver(self, actor, post, origin, event):
        state = self.server.state
        for follower, followed in state['edges']:
            if followed == actor and follower != post['usuario_id']:
                state['feeds'][follower].setdefault(post['id'], {
                    'post': post, 'origem': origin, 'autor_evento_id': actor, 'evento_id': event})


class ReportTests(unittest.TestCase):
    def test_percentiles_and_empty_samples(self):
        self.assertIsNone(runner.stats([]))
        self.assertEqual(runner.stats([10, 30, 20])['p50'], 20)
        self.assertEqual(runner.stats([10])['p99'], 10)

    def test_original_envelopes(self):
        profile = runner.profile(runner.load_config(ROOT / 'config.json'))
        self.assertEqual(profile['social'][-1]['end_s'], 205)
        self.assertEqual(sum(segments[-1]['to'] for segments in profile.values()), 740)
        self.assertEqual(sum((s['end_s'] - s['start_s']) * (s['from'] + s['to']) / 2
                             for s in profile['social']), 54635)

    def test_config_rejects_wrong_types_and_unknown_fields(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'config.json'
            original = runner.load_config(ROOT / 'config.json')
            for patch in ({'users': True}, {'post_fraction': 2}, {'max_vus': 1}, {'extra': 1}):
                path.write_text(json.dumps({**original, **patch}))
                with self.assertRaises(ValueError):
                    runner.load_config(path)

    def test_clean_reset_runs_commands_then_waits_for_api(self):
        with tempfile.TemporaryDirectory() as temporary:
            backend = Path(temporary)
            (backend / 'compose.yaml').touch()
            calls = []
            commands = [(['docker', 'compose', 'down', '--volumes'], backend),
                        (['docker', 'compose', 'up', '-d', '--wait'], backend)]
            def run(command, **kwargs):
                calls.append(command)
                return subprocess.CompletedProcess(command, 0)
            with mock.patch.object(runner, 'reset_commands', return_value=commands):
                with (backend / 'reset.log').open('w') as log:
                    runner.clean_architecture('async', 'http://localhost:9999', log, run,
                                              lambda url: calls.append(url))
            self.assertEqual(calls, [commands[0][0], commands[1][0], 'http://localhost:9999'])

    def test_reset_commands_use_benchmark_only_compose_projects(self):
        async_commands = runner.reset_commands('async')
        sync_commands = runner.reset_commands('sync')

        self.assertTrue(all('tcc-benchmark-async' in command for command, _ in async_commands))
        self.assertTrue(any(any('compose.benchmark.yaml' in value for value in command)
                            for command, _ in async_commands))
        self.assertTrue(all('tcc-benchmark-sync' in command for command, _ in sync_commands))
        self.assertTrue(all(any('compose.benchmark.yaml' in value for value in command)
                            for command, _ in sync_commands))
        self.assertFalse(any('docker-compose.yml' in value for command, _ in sync_commands for value in command))

    def test_clean_reset_stops_on_command_failure_without_waiting(self):
        with tempfile.TemporaryDirectory() as temporary:
            backend = Path(temporary)
            (backend / 'compose.yaml').touch()
            commands = [(['docker', 'compose', 'down', '--volumes'], backend)]
            with mock.patch.object(runner, 'reset_commands', return_value=commands):
                with (backend / 'reset.log').open('w') as log:
                    with self.assertRaisesRegex(RuntimeError, 'Comando de reset falhou'):
                        runner.clean_architecture('async', 'http://localhost:9999', log,
                            lambda command, **kwargs: subprocess.CompletedProcess(command, 1),
                            lambda url: self.fail('API não deve ser verificada'))

    def test_clean_reset_failure_writes_report_without_starting_k6(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            fake_bin = directory / 'bin'
            fake_bin.mkdir()
            marker = directory / 'k6-run-started'
            fake_k6 = fake_bin / 'k6'
            fake_k6.write_text(f'#!/bin/sh\nif [ "$1" = version ]; then echo k6-test; else touch "{marker}"; fi\n')
            fake_k6.chmod(0o755)
            fake_docker = fake_bin / 'docker'
            fake_docker.write_text('#!/bin/sh\nexit 1\n')
            fake_docker.chmod(0o755)
            env = {**os.environ, 'PATH': f'{fake_bin}:{os.environ["PATH"]}'}
            result = subprocess.run(['python3', str(ROOT / 'run.py'), '--base-url',
                'http://127.0.0.1:9999', '--architecture', 'sync', '--clean',
                '--output', str(directory / 'results')], capture_output=True, text=True,
                env=env, timeout=10)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(marker.exists())
            report = json.loads((directory / 'results' / 'report.json').read_text())
            self.assertEqual(report['manifest']['clean']['status'], 'failed')
            self.assertIn('Comando de reset falhou', report['manifest']['clean']['error'])
            self.assertTrue(any('não foi iniciado' in reason
                                for reason in report['measurement']['invalid_reasons']))

    def execute(self, scenario, broken=False):
        server = ThreadingHTTPServer(('127.0.0.1', 0), Fixture)
        server.state = {'users': {}, 'edges': set(), 'posts': {}, 'shares': set(), 'feeds': {}}
        server.lock = threading.Lock()
        server.broken = broken
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                config = {**runner.load_config(ROOT / 'config.json'), 'scenario': scenario,
                          'users': 3, 'rate_scale': .02, 'time_scale': .02,
                          'post_fraction': 1, 'recovery_seconds': 1, 'graceful_stop_seconds': 1,
                          'preallocated_vus': 3, 'max_vus': 20}
                (directory / 'config.json').write_text(json.dumps(config))
                result = subprocess.run(['python3', str(ROOT / 'run.py'), '--base-url',
                    f'http://127.0.0.1:{server.server_port}', '--architecture', 'sync',
                    '--config', str(directory / 'config.json'), '--output', str(directory / 'results')],
                    capture_output=True, text=True, timeout=30)
                report_path = directory / 'results' / 'report.json'
                if not report_path.exists():
                    log = directory / 'results' / 'output.log'
                    self.fail(result.stdout + result.stderr + (log.read_text() if log.exists() else ''))
                report = json.loads(report_path.read_text())
                self.assertEqual(report['manifest']['clean']['requested'], False)
                self.assertEqual(report['manifest']['clean']['status'], 'not_requested')
                if not broken and result.returncode:
                    self.fail((directory / 'results' / 'output.log').read_text())
                self.assertEqual(report['measurement']['status'], 'completed')
                self.assertFalse(report['time']['TF_preserved'])
                self.assertFalse(report['measurement']['official_comparison_ready'])
                self.assertEqual(report['infrastructure']['status'], 'not_measured')
                self.assertGreater(report['actions']['social']['completed'], 0)
                self.assertTrue(report['timeline'])
                self.assertIn('TF_observed', report['time']['cuts'])
                self.assertTrue(any(row['status_counts'].get('422', 0) for row in report['endpoints']))
                if scenario == 'cascade':
                    self.assertGreater(len(server.state['shares']), 0)
                    for user, post in server.state['shares']:
                        self.assertIn(post, server.state['feeds'][user])
                if broken:
                    self.assertEqual(result.returncode, 99)
                    self.assertFalse(report['measurement']['thresholds_passed'])
                else:
                    self.assertTrue(report['measurement']['thresholds_passed'])
                    self.assertTrue(all(row['failed'] == 0 for row in report['endpoints']))
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_real_k6_against_fixture(self):
        for scenario in ('reference', 'fanout', 'cascade', 'growth'):
            with self.subTest(scenario=scenario):
                self.execute(scenario)

    def test_invalid_content_fails_even_with_http_200(self):
        self.execute('fanout', broken=True)


if __name__ == '__main__':
    unittest.main()
