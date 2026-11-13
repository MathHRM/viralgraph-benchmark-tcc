"""Testes do gerador determinístico do corpus de benchmark."""
import csv
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('dataset', ROOT / 'dataset.py')
dataset = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(dataset)


class DatasetTests(unittest.TestCase):
    def small_spec(self, **overrides):
        values = {'users': 10, 'posts': 50, 'follows': 50, 'shares': 50,
                  'feed_limit': 250, 'seed': 7, 'batch_size': 4}
        values.update(overrides)
        return dataset.DatasetSpec(**values)

    def test_generated_corpus_has_requested_counts_and_feed_cap(self):
        with tempfile.TemporaryDirectory() as temporary:
            manifest = dataset.generate_dataset(self.small_spec(), Path(temporary) / 'corpus')

            self.assertEqual(manifest['counts'], {
                'users': 10, 'posts': 50, 'follows': 50,
                'shares': 50, 'feed_items': 250,
            })
            self.assertTrue(all(row['feed_seq'] == '25'
                                for row in self.read_rows(Path(temporary) / 'corpus' / 'users.csv')))
            self.assertLessEqual(manifest['counts']['feed_items'], 250)
            self.assertTrue((Path(temporary) / 'corpus' / 'manifest.json').is_file())

    def test_generation_is_deterministic_for_same_seed(self):
        with tempfile.TemporaryDirectory() as temporary:
            first = Path(temporary) / 'first'
            second = Path(temporary) / 'second'
            dataset.generate_dataset(self.small_spec(), first)
            dataset.generate_dataset(self.small_spec(), second)

            for name in ('users.csv', 'posts.csv', 'follows.csv', 'shares.csv', 'feed_items.csv'):
                self.assertEqual((first / name).read_bytes(), (second / name).read_bytes())

    def test_shares_reference_posts_already_delivered_to_the_actor(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / 'corpus'
            dataset.generate_dataset(self.small_spec(), directory)

            feed = {(row['user_id'], row['post_id']) for row in self.read_rows(directory / 'feed_items.csv')}
            posts = {row['id'] for row in self.read_rows(directory / 'posts.csv')}
            shares = self.read_rows(directory / 'shares.csv')

            self.assertEqual(len(shares), 50)
            self.assertTrue(all(row['post_id'] in posts for row in shares))
            self.assertTrue(all((row['user_id'], row['post_id']) in feed for row in shares))

    def test_rejects_feed_limit_below_required_unique_entries(self):
        with tempfile.TemporaryDirectory() as temporary:
            with self.assertRaisesRegex(ValueError, 'feed_limit'):
                dataset.generate_dataset(self.small_spec(feed_limit=249), Path(temporary) / 'corpus')

    def test_rejects_existing_output_directory(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / 'corpus'
            directory.mkdir()
            with self.assertRaisesRegex(FileExistsError, 'já existe'):
                dataset.generate_dataset(self.small_spec(), directory)

    def test_reference_date_is_recorded_in_all_seeded_records(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / 'corpus'
            manifest = dataset.generate_dataset(
                self.small_spec(reference_date='2025-02-03'), directory)

            self.assertEqual(manifest['reference_date'], '2025-02-03')
            self.assertEqual(self.read_rows(directory / 'users.csv')[0]['created_at'],
                             '2025-02-03 00:00:00')

    def test_cli_accepts_reference_date_for_comparable_seed_data(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / 'corpus'
            result = subprocess.run([
                sys.executable, str(ROOT / 'dataset.py'), '--output', str(directory),
                '--users', '10', '--posts', '50', '--follows', '50', '--shares', '50',
                '--feed-limit', '250', '--seed', '11', '--reference-date', '2025-02-03',
            ], capture_output=True, text=True)

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads((directory / 'manifest.json').read_text())['reference_date'],
                             '2025-02-03')

    @staticmethod
    def read_rows(path):
        with path.open(newline='') as stream:
            return list(csv.DictReader(stream))


if __name__ == '__main__':
    unittest.main()
