"""Integração real; grava apenas no projeto Compose tcc-hyperf-async."""
import concurrent.futures
import json
from pathlib import Path
import subprocess
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid
import unicodedata

ROOT = Path(__file__).resolve().parents[1]
COMPOSE = ["docker", "compose", "-f", str(ROOT / "compose.yaml")]


def compose(*args):
    return subprocess.check_output(COMPOSE + list(args), text=True)


def request(method, path, payload=None, instance=None, raw=None, media="application/json"):
    body = raw if raw is not None else json.dumps(payload, ensure_ascii=False) if payload is not None else None
    if instance:
        result = json.loads(compose("exec", "-T", "api1", "php", "tests/request.php",
                                    instance, method, path, body or ""))
        return result["status"], result["body"], result["headers"]
    req = urllib.request.Request("http://127.0.0.1:9999" + path,
                                 data=body.encode() if body is not None else None,
                                 headers={"Content-Type": media}, method=method)
    try:
        response = urllib.request.urlopen(req, timeout=35)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, json.loads(response.read()), str(response.headers)


def wait_for(fn, timeout=35):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        result = fn()
        if result:
            return result
        time.sleep(.1)
    raise AssertionError("Prazo excedido")


class ContractTest(unittest.TestCase):
    def test_concurrent_post_publish(self):
        author = self.user()
        contents = ["Publicação concorrente " + str(index) for index in range(12)]
        def publish(content):
            status, body, headers = request("POST", "/posts", {"usuario_id": author, "conteudo": content})
            self.assertEqual(status, 201, body)
            self.assertIn("/posts/" + body["id"], headers)
            return body["id"], content
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
            posts = list(pool.map(publish, contents))
        self.assertEqual(len({post for post, _ in posts}), len(contents))
        for post, content in posts:
            def persisted():
                result = request("GET", "/posts/" + post)
                return result[1] if result[0] == 200 else None
            body = wait_for(persisted)
            self.assertEqual((body["usuario_id"], body["conteudo"]), (author, content))

    def test_routing_errors(self):
        route_missing = {"erro": {"codigo": "nao_encontrado", "mensagem": "Rota não encontrada."}}
        resource_missing = {"erro": {"codigo": "nao_encontrado", "mensagem": "Recurso não encontrado."}}
        method_invalid = {"erro": {"codigo": "metodo_invalido", "mensagem": "Método não permitido."}}
        for method, path in [("GET", "/seguir-usuario"), ("GET", "/compartilhar-post"),
                             ("POST", "/metricas"), ("POST", "/usuarios/id"),
                             ("POST", "/posts/id"), ("POST", "/feed/id")]:
            with self.subTest(method=method, path=path):
                status, body, headers = request(method, path, {})
                self.assertEqual((status, body), (404, route_missing))
                self.assertIn("X-Instance", headers)
        self.assertEqual(request("GET", "/inexistente")[:2], (404, resource_missing))
        self.assertEqual(request("PUT", "/usuarios", {})[:2], (405, method_invalid))

    def user(self, name="Pessoa", nickname=None, instance=None):
        status, body, headers = request("POST", "/usuarios", {
            "nome": name, "apelido": nickname or str(uuid.uuid4()), "nascimento": "2000-01-01"
        }, instance)
        self.assertEqual(status, 201, body)
        self.assertIn("/usuarios/" + body["id"], headers)
        self.assertEqual(request("GET", "/usuarios/" + body["id"], instance="api2")[1], body)
        return body["id"]

    def follow(self, follower, followed):
        status, body, _ = request("POST", "/seguir-usuario", {"seguidor_id": follower, "seguido_id": followed})
        self.assertEqual(status, 201, body)

    def post(self, author, content="viral"):
        status, body, headers = request("POST", "/posts", {"usuario_id": author, "conteudo": content})
        self.assertEqual(status, 201, body)
        self.assertIn("/posts/" + body["id"], headers)
        return body["id"]

    def feed(self, user):
        return request("GET", "/feed/" + user)[1]["data"]

    def share(self, user, post):
        return request("POST", "/compartilhar-post", {"usuario_id": user, "post_id": post})

    def test_validation(self):
        valid = {"nome": "Ana", "apelido": str(uuid.uuid4()), "nascimento": "2000-01-01"}
        for patch in ({"nome": 12}, {"nome": None}, {"nome": " "}, {"nome": "a" * 33},
                      {"apelido": "a" * 101}, {"nascimento": "2008-11-09"},
                      {"nascimento": "2000-02-30"}, {"nascimento": "2100-01-01"}):
            self.assertEqual(request("POST", "/usuarios", valid | patch)[0], 422, patch)
        self.assertEqual(request("POST", "/usuarios", raw="{")[0], 400)
        self.assertEqual(request("POST", "/usuarios", valid, media="text/plain")[0], 415)
        for value in ([], 5, None):
            self.assertEqual(request("POST", "/usuarios", raw=json.dumps(value))[0], 422)
        self.assertEqual(request("POST", "/usuarios", valid | {"nascimento": "2008-11-08"})[0], 201)
        for path in ("/usuarios?x=a", "/posts", "/usuarios?s=%20", "/posts?c=%20",
                     "/usuarios?s=" + "a" * 101, "/posts?c=" + "a" * 501,
                     "/posts/not-uuid", "/feed/not-uuid", "/usuarios?s=Ana&cursor=invalid"):
            status, body, _ = request("GET", path)
            self.assertEqual(status, 422, path)
            self.assertIn("erro", body)
        self.assertEqual(request("GET", "/usuarios/" + str(uuid.uuid4()))[0], 404)
        self.assertEqual(request("GET", "/feed/" + str(uuid.uuid4()))[0], 404)
        self.assertEqual(request("GET", "/posts?c=" + str(uuid.uuid4()))[1]["data"], [])
        uid = self.user()
        for content in (" ", "x" * 501, 123, None):
            self.assertEqual(request("POST", "/posts", {"usuario_id": uid, "conteudo": content})[0], 422)
        self.assertEqual(request("POST", "/posts", {"usuario_id": str(uuid.uuid4()), "conteudo": "x"})[0], 404)

    def test_form_requests_validation(self):
        error = {"erro": {"codigo": "validacao", "mensagem": "Dados inválidos."}}
        valid = {"nome": "Ana", "apelido": str(uuid.uuid4()), "nascimento": "2000-01-01"}
        for field in valid:
            payload = {key: value for key, value in valid.items() if key != field}
            self.assertEqual(request("POST", "/usuarios", payload)[:2], (422, error))
        for value in (False, [], {}, "\u2003", "😀" * 33):
            self.assertEqual(request("POST", "/usuarios", valid | {"nome": value})[:2], (422, error))
        self.user(name="😀" * 32)
        self.user(name=" A\u0301" + "a" * 31 + " ")
        author = self.user()
        for path, valid_payload, field in [
            ("/posts", {"usuario_id": author, "conteudo": "texto"}, "usuario_id"),
            ("/seguir-usuario", {"seguidor_id": author, "seguido_id": author}, "seguidor_id"),
            ("/compartilhar-post", {"usuario_id": author, "post_id": author}, "post_id"),
        ]:
            for value in (None, 123, [], {}, "bad", author + "\n"):
                self.assertEqual(request("POST", path, valid_payload | {field: value})[:2], (422, error))
        self.assertEqual(request("GET", "/usuarios/" + author.upper())[1]["id"], author)
        for path in ("/usuarios?s[]=Ana", "/posts?c[]=texto", "/usuarios?s=Ana&cursor[]=x"):
            self.assertEqual(request("GET", path)[:2], (422, error))
        extra_user = valid | {"apelido": str(uuid.uuid4()), "extra": {"qualquer": True}}
        status, body, _ = request("POST", "/usuarios?extra=1", extra_user)
        self.assertEqual(status, 201, body)
        self.assertNotIn("extra", request("GET", "/usuarios/" + body["id"] + "?cursor=x")[1])
        self.assertEqual(request("GET", "/feed/" + author + "?id=ignorado")[0], 200)
        self.assertEqual(request("GET", "/usuarios?s=Ana&extra[]=x")[0], 200)
        self.assertEqual(request("GET", "/metricas?extra=1")[0], 200)
        # Cada FormRequest é reutilizado pelo container; os dados devem ser isolados por requisição.
        def send(index):
            if index % 2:
                return request("GET", "/usuarios?s[]=Ana", instance="api1")[:2] == (422, error)
            result = request("GET", "/usuarios?s=" + author, instance="api1")
            return result[0] == 200 and result[1]["data"] == []
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
            self.assertTrue(all(pool.map(send, range(12))))

    def test_unicode_and_concurrent_uniqueness(self):
        nickname = "á" + str(uuid.uuid4())
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            results = list(pool.map(lambda instance: request("POST", "/usuarios", {
                "nome": " A\u0301na ", "apelido": " " + nickname.replace("á", "a\u0301") + " ",
                "nascimento": "2000-01-01"
            }, instance), ["api1", "api2"]))
        self.assertEqual(sorted(r[0] for r in results), [201, 422])
        winner = next(r[1] for r in results if r[0] == 201)
        self.assertEqual(winner["nome"], "Ána")
        self.assertEqual(winner["apelido"], nickname)
        a, b = winner["id"], self.user()
        for path, payload in [
            ("/seguir-usuario", {"seguidor_id": a, "seguido_id": b}),
        ]:
            with concurrent.futures.ThreadPoolExecutor(2) as pool:
                responses = list(pool.map(lambda instance: request("POST", path, payload, instance), ["api1", "api2"]))
            self.assertEqual(sorted(r[0] for r in responses), [201, 422])
        self.assertEqual(request("POST", "/seguir-usuario", {"seguidor_id": a, "seguido_id": a})[0], 422)
        self.assertEqual(request("POST", "/seguir-usuario", {"seguidor_id": a, "seguido_id": str(uuid.uuid4())})[0], 404)
        p = self.post(b, "  café\u0301 😀  ")
        data = wait_for(lambda: request("GET", "/posts/" + p, instance="api2")[1].get("conteudo"))
        self.assertEqual(data, unicodedata.normalize("NFC", "  café\u0301 😀  "))
        wait_for(lambda: any(x["post"]["id"] == p for x in self.feed(a)))
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            responses = list(pool.map(lambda instance: request("POST", "/compartilhar-post", {
                "usuario_id": a, "post_id": p}, instance), ["api1", "api2"]))
        self.assertEqual(sorted(r[0] for r in responses), [201, 422])

    def test_cascade_first_delivery_recovery(self):
        a, b, c, d = [self.user() for _ in range(4)]
        self.follow(b, a)
        self.follow(c, b)
        self.follow(d, a)
        self.follow(d, b)
        self.follow(a, c)
        before = request("GET", "/metricas")[1]
        compose("stop", "worker")
        try:
            p = self.post(a, "  viral 😀  ")
            self.assertEqual(request("GET", "/posts/" + p, instance="api2")[0], 404)
            post = json.loads(compose("exec", "-T", "api1", "php", "tests/control.php", "commit-without-ack"))
            self.assertEqual(post["id"], p)
            self.assertEqual(request("GET", "/posts/" + p, instance="api2")[1], post)
        finally:
            compose("start", "worker")
        wait_for(lambda: len(self.feed(b)) == 1 and len(self.feed(d)) == 1)
        first = self.feed(d)[0]
        self.assertEqual(first["origem"], "publicacao")
        self.assertEqual(self.share(a, p)[0], 422)
        self.assertEqual(self.share(c, p)[0], 422)
        self.assertEqual(self.share(b, p)[0], 201)
        wait_for(lambda: len(self.feed(c)) == 1)
        self.assertEqual(self.share(c, p)[0], 201)
        wait_for(lambda: json.loads(compose("exec", "-T", "api1", "php", "tests/control.php",
                                            "origins", d, p))["total"] == 2)
        self.assertEqual(self.feed(d), [first])
        self.assertEqual(self.feed(a), [])
        e = self.user()
        self.follow(e, a)
        self.assertEqual(self.feed(e), [])
        compose("exec", "-T", "api1", "php", "tests/control.php", "replay", json.dumps(post))
        compose("restart", "worker")
        time.sleep(1)
        self.assertEqual(self.feed(d), [first])
        after = request("GET", "/metricas")[1]
        self.assertEqual(after["posts_criados"] - before["posts_criados"], 1)
        self.assertEqual(after["compartilhamentos"] - before["compartilhamentos"], 2)
        self.assertEqual(after["entradas_feed"] - before["entradas_feed"], 3)
        self.assertEqual(after["inserts_logicos"], sum(v for k, v in after.items() if k != "inserts_logicos"))

    def test_posts_feed_pagination_and_pending_follow(self):
        author, reader = self.user(), self.user()
        self.follow(reader, author)
        token = str(uuid.uuid4())
        ids = [self.post(author, token + "-" + str(i)) for i in range(52)]
        wait_for(lambda: request("GET", "/posts/" + ids[-1])[0] == 200)
        search = request("GET", "/posts?c=" + token)[1]
        tail = request("GET", "/posts?c=" + token + "&cursor=" + urllib.parse.quote(search["proximo_cursor"]))[1]
        def complete_feed():
            first = request("GET", "/feed/" + reader)[1]
            if not first["proximo_cursor"]:
                return False
            tail = request("GET", "/feed/" + reader + "?cursor=" + urllib.parse.quote(first["proximo_cursor"]))[1]
            return len(first["data"]) + len(tail["data"]) == 52
        wait_for(complete_feed)
        self.assertEqual([p["id"] for p in search["data"] + tail["data"]], sorted(ids))
        feed = request("GET", "/feed/" + reader)[1]
        remaining = request("GET", "/feed/" + reader + "?cursor=" + urllib.parse.quote(feed["proximo_cursor"]))[1]
        self.assertEqual(len(remaining["data"]), 2)
        self.assertEqual(set(x["post"]["id"] for x in feed["data"] + remaining["data"]), set(ids))
        self.assertIsNone(remaining["proximo_cursor"])
        self.assertEqual(request("GET", "/feed/" + author + "?cursor=" + urllib.parse.quote(feed["proximo_cursor"]))[0], 422)
        # A tarefa existe, mas ainda não foi distribuída quando chega o novo seguidor.
        compose("stop", "worker")
        try:
            pending = self.post(author)
            committed = json.loads(compose("exec", "-T", "api1", "php", "tests/control.php", "commit-without-ack"))
            self.assertEqual(committed["id"], pending)
            late = self.user()
            self.follow(late, author)
        finally:
            compose("start", "worker")
        wait_for(lambda: any(x["post"]["id"] == pending for x in self.feed(reader)))
        self.assertEqual(self.feed(late), [])

    def test_fanout_multiple_batches_and_broker_outage(self):
        author = self.user()
        readers = [self.user() for _ in range(101)]
        for reader in readers:
            self.follow(reader, author)
        p = self.post(author)
        wait_for(lambda: all(any(e["post"]["id"] == p for e in self.feed(u)) for u in readers), timeout=90)
        downstream = self.user()
        self.follow(downstream, readers[0])
        compose("stop", "rabbitmq")
        try:
            status, body, _ = request("POST", "/posts", {"usuario_id": author, "conteudo": "broker parado"})
            self.assertEqual(status, 503, body)
            self.assertEqual(self.share(readers[0], p)[0], 201)
            self.assertEqual(self.feed(downstream), [])
        finally:
            compose("start", "rabbitmq")
        wait_for(lambda: len(self.feed(downstream)) == 1, timeout=90)

    def test_rabbitmq_recreation_preserves_pending_post(self):
        author = self.user()
        compose("stop", "worker")
        try:
            post = self.post(author, "pendente durante recriação do broker")
            self.assertEqual(request("GET", "/posts/" + post)[0], 404)
            compose("up", "-d", "--no-deps", "--force-recreate", "--wait", "--wait-timeout", "120", "rabbitmq")
            self.assertEqual(request("GET", "/posts/" + post)[0], 404)
        finally:
            compose("start", "worker")
        wait_for(lambda: request("GET", "/posts/" + post, instance="api2")[0] == 200, timeout=90)

    def test_search_pagination(self):
        token = str(uuid.uuid4())
        ids = [self.user(name=token[:32], nickname=token + "-" + str(i)) for i in range(52)]
        path = "/usuarios?s=" + token
        first = request("GET", path)[1]
        self.assertEqual(len(first["data"]), 50)
        self.assertIsNotNone(first["proximo_cursor"])
        second = request("GET", path + "&cursor=" + urllib.parse.quote(first["proximo_cursor"]))[1]
        self.assertEqual(len(second["data"]), 2)
        self.assertIsNone(second["proximo_cursor"])
        self.assertEqual([u["id"] for u in first["data"] + second["data"]], sorted(ids))
        self.assertEqual(request("GET", "/usuarios?s=other&cursor=" + urllib.parse.quote(first["proximo_cursor"]))[0], 422)


if __name__ == "__main__":
    unittest.main()
