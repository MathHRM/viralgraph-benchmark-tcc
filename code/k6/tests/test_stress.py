"""Testes do gerador; a API em memória é uma fixture, não uma arquitetura do TCC."""
import importlib.util
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import subprocess
import tempfile
import threading
import unittest
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
            elif self.path == '/compartilhar-post':
                pair = (body['usuario_id'], body['post_id'])
                if pair in state['shares'] or body['post_id'] not in state['feeds'][body['usuario_id']]:
                    return self.reply(422, {})
                state['shares'].add(pair)
                self.deliver(body['usuario_id'], state['posts'][body['post_id']], 'compartilhamento', identifier)
            else:
                return self.reply(404, {})
            self.reply(201, data, location)

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
