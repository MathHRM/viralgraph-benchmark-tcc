#!/usr/bin/env python3
"""Gera o snapshot do cenário de viralização (grafo Zipf) para MySQL e Neo4j.

Saída (em --output):
  mysql/seed.sql.gz    INSERTs multi-linha; aplicar após `php artisan migrate`.
  neo4j/*.csv.gz       carga offline com `neo4j-admin database import full`;
  neo4j/schema.cypher  constraints/índices aplicados após o import (cypher-shell).
  k6/dataset.json      IDs para a carga: comuns, celebridades, posts virais e candidatos a compartilhar.
  manifest.json        parâmetros, contagens e distribuição observada.

Mesma seed => mesmos IDs, grafo, cascatas e feed nas duas arquiteturas.
"""
from __future__ import annotations

import argparse
import csv
from dataclasses import asdict, dataclass
from datetime import datetime, timedelta, timezone
import gzip
import hashlib
import io
import json
from pathlib import Path
import random
import statistics
import sys
import uuid


@dataclass(frozen=True)
class Spec:
    seed: int = 42
    common_users: int = 10_000
    celebrities: int = 20
    common_max_followers: int = 30
    celebrity_min_followers: int = 2_000
    celebrity_max_followers: int = 5_000
    zipf_s: float = 1.0
    celebrity_posts: int = 5
    common_max_posts: int = 3
    viral_common_posts: int = 50
    celebrity_share_probability: float = 0.03
    common_share_probability: float = 0.25
    cascade_share_probability: float = 0.12
    cascade_max_depth: int = 6
    cascade_max_shares: int = 800
    batch_size: int = 1_000
    reference_date: str = '2026-11-08'

    def validate(self) -> None:
        if self.common_users < self.celebrity_max_followers:
            raise ValueError('common_users precisa ser >= celebrity_max_followers (seguidores vêm dos comuns).')
        if not 1 <= self.common_max_followers < self.common_users:
            raise ValueError('common_max_followers fora do intervalo.')
        if not 0 < self.celebrity_min_followers <= self.celebrity_max_followers:
            raise ValueError('Faixa de seguidores de celebridades inválida.')
        if self.celebrities < 1 or self.zipf_s <= 0:
            raise ValueError('celebrities >= 1 e zipf_s > 0.')
        if not 1 <= self.batch_size <= 10_000:
            raise ValueError('batch_size entre 1 e 10000.')
        datetime.strptime(self.reference_date, '%Y-%m-%d')


class Ids:
    """UUIDs v4 determinísticos: um gerador por tipo, derivado da seed."""

    def __init__(self, seed: int):
        self.seed = seed
        self.streams: dict[str, random.Random] = {}

    def new(self, kind: str) -> str:
        rng = self.streams.setdefault(kind, random.Random(f'{self.seed}:{kind}'))
        return str(uuid.UUID(int=rng.getrandbits(128), version=4))


def zipf_degree(rng: random.Random, maximum: int, s: float, weights_cache: dict) -> int:
    """Grau em [1, maximum] com P(k) ∝ k^-s (Zipf truncada)."""
    key = (maximum, s)
    if key not in weights_cache:
        weights_cache[key] = [k ** -s for k in range(1, maximum + 1)]
    return rng.choices(range(1, maximum + 1), weights=weights_cache[key])[0]


def celebrity_degree(spec: Spec, rank: int) -> int:
    """Rank 1 recebe o máximo; cauda Zipf decai até o mínimo."""
    span = spec.celebrity_max_followers - spec.celebrity_min_followers
    return spec.celebrity_min_followers + int(span / rank ** spec.zipf_s)


def build(spec: Spec) -> dict:
    spec.validate()
    rng = random.Random(spec.seed)
    ids = Ids(spec.seed)
    reference = datetime.strptime(spec.reference_date, '%Y-%m-%d').replace(tzinfo=timezone.utc)
    graph_time = reference - timedelta(days=30)

    users = []
    for index in range(spec.celebrities):
        users.append({'id': ids.new('user'), 'name': f'Celebridade {index}',
                      'nickname': f'v{spec.seed}-celeb{index}', 'kind': 'celebrity'})
    for index in range(spec.common_users):
        users.append({'id': ids.new('user'), 'name': f'Pessoa {index}',
                      'nickname': f'v{spec.seed}-u{index}', 'kind': 'common'})
    celebs = users[:spec.celebrities]
    commons = users[spec.celebrities:]
    common_ids = [u['id'] for u in commons]

    # followers[followed] = seguidores; seguidores são sempre usuários comuns.
    followers: dict[str, list[str]] = {}
    cache: dict = {}
    for rank, celeb in enumerate(celebs, start=1):
        followers[celeb['id']] = rng.sample(common_ids, celebrity_degree(spec, rank))
    for user in commons:
        degree = zipf_degree(rng, spec.common_max_followers, spec.zipf_s, cache)
        chosen: set[str] = set()
        while len(chosen) < degree:
            candidate = common_ids[rng.randrange(len(common_ids))]
            if candidate != user['id']:
                chosen.add(candidate)
        followers[user['id']] = list(chosen)
    for key in followers:
        followers[key].sort()

    follows = []
    for followed, items in followers.items():
        for follower in items:
            follows.append({'id': ids.new('follow'), 'follower_id': follower, 'followed_id': followed,
                            'created_at': graph_time})

    # Posts: celebridades publicam celebrity_posts; comuns de 0 a common_max_posts.
    posts = []
    post_window = timedelta(days=7).total_seconds()
    def new_post(author: str, ordinal: int) -> dict:
        created = reference - timedelta(days=8) + timedelta(seconds=rng.uniform(0, post_window))
        created = created.replace(microsecond=0)
        return {'id': ids.new('post'), 'user_id': author, 'created_at': created,
                'content': f'viral seed{spec.seed} post{ordinal}'}
    for celeb in celebs:
        for _ in range(spec.celebrity_posts):
            posts.append(new_post(celeb['id'], len(posts)) | {'viral': True})
    common_posts = []
    for user in commons:
        for _ in range(rng.randint(0, spec.common_max_posts)):
            post = new_post(user['id'], len(posts)) | {'viral': False}
            posts.append(post)
            common_posts.append(post)
    for post in rng.sample(common_posts, min(spec.viral_common_posts, len(common_posts))):
        post['viral'] = True

    # Cascatas: BFS a partir do autor; cada seguidor do nó atual compartilha com probabilidade p.
    shares = []
    celeb_ids = {c['id'] for c in celebs}
    for post in posts:
        if not post['viral']:
            continue
        author = post['user_id']
        shared: set[str] = set()
        frontier = [author]
        for depth in range(1, spec.cascade_max_depth + 1):
            nxt = []
            for source in frontier:
                if depth == 1:
                    p = spec.celebrity_share_probability if source in celeb_ids else spec.common_share_probability
                else:
                    p = spec.cascade_share_probability
                for follower in followers.get(source, []):
                    if follower == author or follower in shared or rng.random() >= p:
                        continue
                    if len(shared) >= spec.cascade_max_shares:
                        break
                    shared.add(follower)
                    created = (post['created_at'] + timedelta(minutes=depth * 10, seconds=rng.randint(0, 599)))
                    shares.append({'id': ids.new('share'), 'user_id': follower, 'post_id': post['id'],
                                   'source_user_id': source, 'created_at': created, 'depth': depth})
                    nxt.append(follower)
            frontier = nxt
            if not frontier:
                break

    # Feed materializado (fan-out na escrita), em ordem cronológica dos eventos.
    author_of = {p['id']: p['user_id'] for p in posts}
    events = [(p['created_at'], 0, p['id'], p['user_id'], 'publication', p['id']) for p in posts]
    events += [(s['created_at'], 1, s['post_id'], s['user_id'], 'share', s['id']) for s in shares]
    events.sort(key=lambda e: (e[0], e[1], e[5]))
    seq: dict[str, int] = {}
    delivered: set[tuple[str, str]] = set()
    feed = []
    for _, _, post_id, actor, kind, event_id in events:
        author = author_of[post_id]
        for recipient in followers.get(actor, []):
            if recipient == author or (recipient, post_id) in delivered:
                continue
            delivered.add((recipient, post_id))
            seq[recipient] = seq.get(recipient, 0) + 1
            feed.append({'id': ids.new('feed'), 'user_id': recipient, 'post_id': post_id, 'kind': kind,
                         'event_author_id': actor, 'event_id': event_id, 'seq': seq[recipient]})

    for user in users:
        user['feed_seq'] = seq.get(user['id'], 0)
    return {'users': users, 'follows': follows, 'posts': posts, 'shares': shares, 'feed': feed,
            'followers': followers}


# ---------------------------------------------------------------------------- SQL (MySQL)

def sql_value(value) -> str:
    if value is None:
        return 'NULL'
    if isinstance(value, int):
        return str(value)
    if isinstance(value, datetime):
        return "'" + value.strftime('%Y-%m-%d %H:%M:%S') + "'"
    return "'" + str(value).replace('\\', '\\\\').replace("'", "''") + "'"


def sql_inserts(table: str, columns: list[str], rows, batch: int):
    head = f'INSERT INTO `{table}` (' + ', '.join(f'`{c}`' for c in columns) + ') VALUES\n'
    chunk = []
    for row in rows:
        chunk.append('(' + ', '.join(sql_value(row[c]) for c in columns) + ')')
        if len(chunk) == batch:
            yield head + ',\n'.join(chunk) + ';\n'
            chunk = []
    if chunk:
        yield head + ',\n'.join(chunk) + ';\n'


def write_sql(data: dict, spec: Spec, path: Path) -> None:
    created = datetime.strptime(spec.reference_date, '%Y-%m-%d') - timedelta(days=60)
    origin = {'publication': 'post', 'share': 'share'}  # valores gravados pelo Laravel em runtime
    tables = [
        ('users', ['id', 'name', 'nickname', 'birth_date', 'created_at', 'updated_at', 'feed_seq'],
         ({**u, 'birth_date': '2000-01-15', 'created_at': created, 'updated_at': created} for u in data['users'])),
        ('follows', ['id', 'follower_id', 'followed_id', 'created_at'], data['follows']),
        ('posts', ['id', 'user_id', 'content', 'created_at'], data['posts']),
        ('shares', ['id', 'user_id', 'post_id', 'source_user_id', 'created_at'], data['shares']),
        ('feed_items', ['id', 'user_id', 'post_id', 'origin', 'event_author_id', 'event_id', 'seq'],
         ({**f, 'origin': origin[f['kind']]} for f in data['feed'])),
    ]
    with gzip_text(path) as out:
        out.write('-- Snapshot de viralização; gerado por code/dataset/generate.py. Aplicar após as migrations.\n')
        out.write('SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET autocommit=0;\n')
        for table, columns, rows in tables:
            for statement in sql_inserts(table, columns, rows, spec.batch_size):
                out.write(statement)
            out.write('COMMIT;\n')
        out.write('SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n')


# ---------------------------------------------------------------------------- Cypher (Neo4j)

# Espelha App\Infrastructure\Neo4j\SchemaInitializer (CREATE ... IF NOT EXISTS é idempotente).
NEO4J_SCHEMA = [
    'CREATE CONSTRAINT IF NOT EXISTS FOR (n:User) REQUIRE n.id IS UNIQUE',
    'CREATE CONSTRAINT IF NOT EXISTS FOR (n:User) REQUIRE n.apelido IS UNIQUE',
    'CREATE CONSTRAINT IF NOT EXISTS FOR (n:Post) REQUIRE n.id IS UNIQUE',
    'CREATE CONSTRAINT IF NOT EXISTS FOR (n:Task) REQUIRE n.id IS UNIQUE',
    'CREATE CONSTRAINT IF NOT EXISTS FOR ()-[r:FOLLOWS]-() REQUIRE r.key IS UNIQUE',
    'CREATE CONSTRAINT IF NOT EXISTS FOR ()-[r:SHARED]-() REQUIRE r.key IS UNIQUE',
    'CREATE CONSTRAINT IF NOT EXISTS FOR ()-[r:RESHARED_FROM]-() REQUIRE r.key IS UNIQUE',
    'CREATE CONSTRAINT IF NOT EXISTS FOR ()-[r:FEED]-() REQUIRE r.key IS UNIQUE',
    'CREATE CONSTRAINT IF NOT EXISTS FOR ()-[r:VIA]-() REQUIRE r.key IS UNIQUE',
    'CREATE INDEX task_pending IF NOT EXISTS FOR (n:Task) ON (n.published, n.done)',
    'CREATE INDEX follows_target_follower IF NOT EXISTS FOR ()-[f:FOLLOWS]-() ON (f.seguido_id, f.seguidor_id)',
    'CALL db.awaitIndexes(300)',
]


def gzip_text(path: Path, newline=None):
    """gzip sem timestamp no cabeçalho: mesma seed => mesmos bytes (sha256 do manifesto estável)."""
    raw = gzip.GzipFile(filename='', fileobj=path.open('wb'), mode='wb', compresslevel=6, mtime=0)
    return io.TextIOWrapper(raw, encoding='utf-8', newline=newline)


def write_csv(path: Path, header: list[str], rows) -> None:
    with gzip_text(path, newline='') as out:
        writer = csv.writer(out)
        writer.writerow(header)
        writer.writerows(rows)


def write_neo4j(data: dict, directory: Path) -> None:
    """CSVs no formato do `neo4j-admin database import full` (carga offline em lote) e o schema.

    O import não valida constraints; unicidade das chaves é garantida pelo gerador e as
    constraints são criadas logo depois por schema.cypher (falhariam se houvesse duplicata).
    """
    origin = {'publication': 'publicacao', 'share': 'compartilhamento'}
    iso = lambda value: value.strftime('%Y-%m-%dT%H:%M:%SZ')
    write_csv(directory / 'users.csv.gz', ['id:ID(User)', 'nome', 'apelido', 'nascimento', 'feed_seq:long'],
              ([u['id'], u['name'], u['nickname'], '2000-01-15', u['feed_seq']] for u in data['users']))
    write_csv(directory / 'posts.csv.gz', ['id:ID(Post)', 'usuario_id', 'conteudo', 'criado_em'],
              ([p['id'], p['user_id'], p['content'], iso(p['created_at'])] for p in data['posts']))
    write_csv(directory / 'authored.csv.gz', [':START_ID(User)', ':END_ID(Post)'],
              ([p['user_id'], p['id']] for p in data['posts']))
    write_csv(directory / 'follows.csv.gz',
              [':START_ID(User)', ':END_ID(User)', 'key', 'id', 'seguidor_id', 'seguido_id', 'criado_em', 'at:long'],
              ([f['follower_id'], f['followed_id'], f"{f['follower_id']}:{f['followed_id']}", f['id'],
                f['follower_id'], f['followed_id'], iso(f['created_at']), 0] for f in data['follows']))
    write_csv(directory / 'shared.csv.gz',
              [':START_ID(User)', ':END_ID(Post)', 'key', 'id', 'usuario_id', 'post_id', 'origem_usuario_id', 'criado_em'],
              ([s['user_id'], s['post_id'], f"{s['user_id']}:{s['post_id']}", s['id'], s['user_id'], s['post_id'],
                s['source_user_id'], iso(s['created_at'])] for s in data['shares']))
    write_csv(directory / 'reshared_from.csv.gz',
              [':START_ID(User)', ':END_ID(User)', 'key', 'post_id', 'share_id', 'criado_em'],
              ([s['user_id'], s['source_user_id'], f"{s['user_id']}:{s['post_id']}", s['post_id'], s['id'],
                iso(s['created_at'])] for s in data['shares']))
    write_csv(directory / 'feed.csv.gz',
              [':START_ID(User)', ':END_ID(Post)', 'key', 'seq:long', 'origem', 'autor_evento_id', 'evento_id'],
              ([f['user_id'], f['post_id'], f"{f['user_id']}:{f['post_id']}", f['seq'], origin[f['kind']],
                f['event_author_id'], f['event_id']] for f in data['feed']))
    (directory / 'schema.cypher').write_text(''.join(st + ';\n' for st in NEO4J_SCHEMA), encoding='utf-8')


# ---------------------------------------------------------------------------- k6 + manifest

def k6_dataset(data: dict, spec: Spec, max_receivers: int = 1_000) -> dict:
    """Candidatos válidos a compartilhar cada post viral: quem tem o post no feed e ainda não o
    compartilhou, com a origem = quem entregou (autor ou compartilhador). Respeita as regras da API."""
    rng = random.Random(f'{spec.seed}:k6')
    sharers: dict[str, set[str]] = {}
    for share in data['shares']:
        sharers.setdefault(share['post_id'], set()).add(share['user_id'])
    viral = [p for p in data['posts'] if p['viral']]
    viral_ids = {p['id'] for p in viral}
    receivers: dict[str, list[list[str]]] = {}
    for entry in data['feed']:
        if entry['post_id'] in viral_ids and entry['user_id'] not in sharers.get(entry['post_id'], ()):
            receivers.setdefault(entry['post_id'], []).append([entry['user_id'], entry['event_author_id']])
    # Ordena por tamanho da cascata: o k6 escolhe posts com Zipf sobre esse ranking.
    viral.sort(key=lambda p: (-len(sharers.get(p['id'], ())), p['id']))
    def sample(items):
        return items if len(items) <= max_receivers else rng.sample(items, max_receivers)
    return {
        'seed': spec.seed,
        'common_users': [u['id'] for u in data['users'] if u['kind'] == 'common'],
        'celebrities': [u['id'] for u in data['users'] if u['kind'] == 'celebrity'],
        'viral_posts': [{'id': p['id'], 'author': p['user_id'], 'shares': len(sharers.get(p['id'], ())),
                         'receivers': sample(receivers.get(p['id'], []))} for p in viral],
    }


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1 << 20), b''):
            h.update(block)
    return h.hexdigest()


def generate(spec: Spec, output: Path) -> dict:
    if output.exists() and any(output.iterdir()):
        raise FileExistsError(f'Diretório de saída não vazio: {output}')
    data = build(spec)
    for sub in ('mysql', 'neo4j', 'k6'):
        (output / sub).mkdir(parents=True, exist_ok=True)
    write_sql(data, spec, output / 'mysql' / 'seed.sql.gz')
    write_neo4j(data, output / 'neo4j')
    (output / 'k6' / 'dataset.json').write_text(json.dumps(k6_dataset(data, spec)), encoding='utf-8')

    celeb_ids = [u['id'] for u in data['users'] if u['kind'] == 'celebrity']
    common_degrees = [len(data['followers'][u['id']]) for u in data['users'] if u['kind'] == 'common']
    depths: dict[int, int] = {}
    for share in data['shares']:
        depths[share['depth']] = depths.get(share['depth'], 0) + 1
    counts = {'usuarios': len(data['users']), 'feeds': len(data['users']), 'relacoes_seguir': len(data['follows']),
              'posts_criados': len(data['posts']), 'compartilhamentos': len(data['shares']),
              'entradas_feed': len(data['feed'])}
    counts['inserts_logicos'] = sum(counts.values())
    manifest = {
        'schema_version': 1,
        'profile': 'viral_zipf_v1',
        'spec': asdict(spec),
        'counts': counts,  # mesmas chaves de GET /metricas
        'distribution': {
            'celebrity_followers': [len(data['followers'][c]) for c in celeb_ids],
            'common_followers': {'min': min(common_degrees), 'max': max(common_degrees),
                                 'mean': round(statistics.mean(common_degrees), 3),
                                 'median': statistics.median(common_degrees)},
            'viral_posts': sum(p['viral'] for p in data['posts']),
            'shares_by_depth': dict(sorted(depths.items())),
        },
        'files': {str(p.relative_to(output)): {'bytes': p.stat().st_size, 'sha256': digest(p)}
                  for p in sorted(output.rglob('*')) if p.is_file()},
    }
    (output / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    return manifest


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--output', type=Path, required=True)
    for field, default in asdict(Spec()).items():
        parser.add_argument('--' + field.replace('_', '-'), type=type(default), default=default)
    args = vars(parser.parse_args(argv))
    output = args.pop('output')
    try:
        manifest = generate(Spec(**args), output)
    except (ValueError, FileExistsError, OSError) as error:
        parser.error(str(error))
    print(json.dumps({'output': str(output), 'counts': manifest['counts'],
                      'distribution': manifest['distribution']}, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())
