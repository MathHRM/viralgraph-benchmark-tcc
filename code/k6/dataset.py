#!/usr/bin/env python3
"""Gera em streaming um corpus social determinístico em CSV para os backends."""
from dataclasses import asdict, dataclass
import argparse
import csv
from datetime import datetime
import hashlib
import json
from pathlib import Path
import sys
import uuid

ROOT = Path(__file__).resolve().parent
NAMESPACE_CODES = {'user': 1, 'post': 2, 'follow': 3, 'share': 4, 'feed': 5}
HUB_COUNT = 5
REFERENCE_DATE = '2026-11-08'
POSTS_PER_USER = 5
FOLLOWS_PER_USER = 5
SHARES_PER_USER = 5


@dataclass(frozen=True)
class DatasetSpec:
    users: int = 1_000_000
    posts: int = 5_000_000
    follows: int = 5_000_000
    shares: int = 5_000_000
    feed_limit: int = 25_000_000
    seed: int = 42
    batch_size: int = 1_000
    reference_date: str = REFERENCE_DATE

    def validate(self):
        for name, value in asdict(self).items():
            if name == 'reference_date':
                continue
            if isinstance(value, bool) or not isinstance(value, int):
                raise ValueError(f'{name}: inteiro obrigatório.')
            if value < 0:
                raise ValueError(f'{name}: valor não pode ser negativo.')
        if not HUB_COUNT < self.users:
            raise ValueError(f'users deve ser maior que {HUB_COUNT}.')
        if self.seed >= (1 << 28):
            raise ValueError('seed deve estar entre 0 e 2^28-1 para IDs UUID determinísticos.')
        if self.batch_size < 1 or self.batch_size > 10_000:
            raise ValueError('batch_size deve estar entre 1 e 10000.')
        try:
            datetime.strptime(self.reference_date, '%Y-%m-%d')
        except (TypeError, ValueError) as error:
            raise ValueError('reference_date deve usar o formato AAAA-MM-DD.') from error
        if self.posts < self.users or self.posts % self.users:
            raise ValueError('posts deve ser múltiplo de users e garantir ao menos um post por usuário.')
        if self.follows != FOLLOWS_PER_USER * self.users:
            raise ValueError(f'follows deve ser igual a {FOLLOWS_PER_USER} * users.')
        if self.shares % self.users or self.shares > SHARES_PER_USER * self.users:
            raise ValueError(f'shares deve ser múltiplo de users e não exceder {SHARES_PER_USER} por usuário.')
        if self.shares // self.users > FOLLOWS_PER_USER * (self.posts // self.users):
            raise ValueError('Não há publicações suficientes no feed para satisfazer shares.')
        required_feed = FOLLOWS_PER_USER * self.posts
        if self.feed_limit < required_feed:
            raise ValueError(f'feed_limit ({self.feed_limit}) menor que o feed inicial necessário ({required_feed}).')


def stable_id(seed, kind, index):
    """UUID versão 4 válido, mas reproduzível por seed, tipo e índice."""
    if kind not in NAMESPACE_CODES or not 0 <= index < (1 << 62):
        raise ValueError('Tipo ou índice de ID fora do intervalo.')
    prefix = (seed << 4) | NAMESPACE_CODES[kind]
    value = (prefix << 96) | (4 << 76) | (2 << 62) | index
    return str(uuid.UUID(int=value))


def user_id(spec, index):
    return stable_id(spec.seed, 'user', index)


def post_id(spec, index):
    return stable_id(spec.seed, 'post', index)


def followed_users(index):
    hubs = [hub for hub in range(HUB_COUNT) if hub != index]
    if index < HUB_COUNT:
        filler_count = FOLLOWS_PER_USER - len(hubs)
        pool_size = 1
        fillers = [HUB_COUNT + ((index + offset) % pool_size) for offset in range(filler_count)]
        return hubs + fillers
    return hubs


def write_rows(path, columns, rows):
    count = 0
    with path.open('w', encoding='utf-8', newline='') as stream:
        writer = csv.DictWriter(stream, fieldnames=columns, extrasaction='raise')
        writer.writeheader()
        for row in rows:
            writer.writerow(row)
            count += 1
    return count


def iter_users(spec):
    created_at = f'{spec.reference_date} 00:00:00'
    for index in range(spec.users):
        yield {'id': user_id(spec, index), 'name': f'Pessoa {index}',
               'nickname': f's{spec.seed}-u{index}', 'birth_date': '2000-01-15',
               'created_at': created_at, 'updated_at': created_at,
               'feed_seq': FOLLOWS_PER_USER * (spec.posts // spec.users)}


def iter_posts(spec):
    created_at = f'{spec.reference_date} 00:00:00'
    for index in range(spec.posts):
        author = index % spec.users
        yield {'id': post_id(spec, index), 'user_id': user_id(spec, author),
               'content': f'viral corpus seed{spec.seed} post{index}', 'created_at': created_at}


def iter_follows(spec):
    created_at = f'{spec.reference_date} 00:00:00'
    index = 0
    for follower in range(spec.users):
        for followed in followed_users(follower):
            yield {'id': stable_id(spec.seed, 'follow', index),
                   'follower_id': user_id(spec, follower),
                   'followed_id': user_id(spec, followed), 'created_at': created_at}
            index += 1


def feed_candidates(spec, user_index):
    posts_per_user = spec.posts // spec.users
    for author_index in followed_users(user_index):
        for ordinal in range(posts_per_user):
            post_index = ordinal * spec.users + author_index
            yield author_index, post_index


def iter_shares(spec):
    created_at = f'{spec.reference_date} 00:00:00'
    per_user = spec.shares // spec.users
    index = 0
    for user_index in range(spec.users):
        for _, (_, post_index) in zip(range(per_user), feed_candidates(spec, user_index)):
            yield {'id': stable_id(spec.seed, 'share', index),
                   'user_id': user_id(spec, user_index), 'post_id': post_id(spec, post_index),
                   'created_at': created_at}
            index += 1


def iter_feed_items(spec):
    feed_index = 0
    for user_index in range(spec.users):
        seq = 0
        for author_index, post_index in feed_candidates(spec, user_index):
            seq += 1
            yield {'id': stable_id(spec.seed, 'feed', feed_index),
                   'user_id': user_id(spec, user_index), 'post_id': post_id(spec, post_index),
                   'origin': 'post', 'event_author_id': user_id(spec, author_index),
                   'event_id': post_id(spec, post_index), 'seq': seq}
            feed_index += 1


def digest(path):
    value = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            value.update(block)
    return value.hexdigest()


def generate_dataset(spec, output):
    spec.validate()
    output = Path(output)
    if output.exists():
        raise FileExistsError(f'O diretório de saída já existe: {output}')
    output.mkdir(parents=True)

    schemas = {
        'users.csv': (('id', 'name', 'nickname', 'birth_date', 'created_at', 'updated_at', 'feed_seq'),
                      iter_users(spec)),
        'posts.csv': (('id', 'user_id', 'content', 'created_at'), iter_posts(spec)),
        'follows.csv': (('id', 'follower_id', 'followed_id', 'created_at'), iter_follows(spec)),
        'shares.csv': (('id', 'user_id', 'post_id', 'created_at'), iter_shares(spec)),
        'feed_items.csv': (('id', 'user_id', 'post_id', 'origin', 'event_author_id', 'event_id', 'seq'),
                           iter_feed_items(spec)),
    }
    counts = {}
    for name, (columns, rows) in schemas.items():
        counts[name.removesuffix('.csv')] = write_rows(output / name, columns, rows)
    counts['feed_items'] = counts.pop('feed_items')
    expected = {'users': spec.users, 'posts': spec.posts, 'follows': spec.follows,
                'shares': spec.shares, 'feed_items': FOLLOWS_PER_USER * spec.posts}
    if counts != expected:
        raise RuntimeError(f'Contagens geradas divergiram do esperado: {counts} != {expected}')
    if counts['feed_items'] > spec.feed_limit:
        raise RuntimeError('O limite máximo de entradas de feed foi excedido.')

    manifest = {
        'schema_version': 1,
        'profile': 'hub_concentrated_v1',
        'seed': spec.seed,
        'reference_date': spec.reference_date,
        'spec': asdict(spec),
        'counts': counts,
        'distribution': {
            'hub_users': [user_id(spec, index) for index in range(HUB_COUNT)],
            'follow_edges_per_user': FOLLOWS_PER_USER,
            'posts_per_user': spec.posts // spec.users,
            'shares_per_user': spec.shares // spec.users,
            'feed_limit': spec.feed_limit,
            'share_delivery_adds_unique_feed_items': False,
        },
        'files': {name: {'bytes': (output / name).stat().st_size,
                         'sha256': digest(output / name)} for name in schemas},
    }
    (output / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n',
                                         encoding='utf-8')
    return manifest


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--users', type=int, default=1_000_000)
    parser.add_argument('--posts', type=int, default=5_000_000)
    parser.add_argument('--follows', type=int, default=5_000_000)
    parser.add_argument('--shares', type=int, default=5_000_000)
    parser.add_argument('--feed-limit', type=int, default=25_000_000)
    parser.add_argument('--seed', type=int, default=42)
    parser.add_argument('--batch-size', type=int, default=1_000)
    parser.add_argument('--reference-date', default=REFERENCE_DATE)
    args = parser.parse_args(argv)
    spec = DatasetSpec(users=args.users, posts=args.posts, follows=args.follows,
                       shares=args.shares, feed_limit=args.feed_limit, seed=args.seed,
                       batch_size=args.batch_size, reference_date=args.reference_date)
    try:
        manifest = generate_dataset(spec, args.output)
    except (FileExistsError, OSError, ValueError, RuntimeError) as error:
        parser.error(str(error))
    print(json.dumps({'output': str(args.output), 'counts': manifest['counts']}, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())
