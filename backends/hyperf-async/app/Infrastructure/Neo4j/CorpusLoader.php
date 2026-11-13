<?php
declare(strict_types=1);

namespace App\Infrastructure\Neo4j;

use Hyperf\Coroutine\Parallel;
use RuntimeException;

final class CorpusLoader
{
    private const FILES = [
        'users.csv' => ['columns' => ['id', 'name', 'nickname', 'birth_date', 'created_at', 'updated_at', 'feed_seq'],
            'cypher' => 'UNWIND $rows AS row CREATE (u:User {id:row.id, nome:row.name, apelido:row.nickname,
                nascimento:row.birth_date, feed_seq:toInteger(row.feed_seq)})'],
        'posts.csv' => ['columns' => ['id', 'user_id', 'content', 'created_at'],
            'cypher' => 'UNWIND $rows AS row MATCH (u:User {id:row.user_id})
                CREATE (p:Post {id:row.id, usuario_id:row.user_id, conteudo:row.content, criado_em:row.created_at})
                CREATE (u)-[:AUTHORED]->(p)'],
        'follows.csv' => ['columns' => ['id', 'follower_id', 'followed_id', 'created_at'],
            'cypher' => 'UNWIND $rows AS row MATCH (a:User {id:row.follower_id})
                MATCH (b:User {id:row.followed_id})
                CREATE (a)-[:FOLLOWS {key:row.follower_id + ":" + row.followed_id,
                    seguidor_id:row.follower_id, seguido_id:row.followed_id, at:0}]->(b)'],
        'shares.csv' => ['columns' => ['id', 'user_id', 'post_id', 'created_at'],
            'cypher' => 'UNWIND $rows AS row MATCH (u:User {id:row.user_id})
                MATCH (p:Post {id:row.post_id})
                CREATE (u)-[:SHARED {id:row.id, key:row.user_id + ":" + row.post_id,
                    usuario_id:row.user_id, post_id:row.post_id, criado_em:row.created_at}]->(p)'],
        'feed_items.csv' => ['columns' => ['id', 'user_id', 'post_id', 'origin', 'event_author_id', 'event_id', 'seq'],
            'cypher' => 'UNWIND $rows AS row MATCH (u:User {id:row.user_id})
                MATCH (p:Post {id:row.post_id})
                CREATE (u)-[:FEED {key:row.user_id + ":" + row.post_id, seq:toInteger(row.seq),
                    origem:row.origin, autor_evento_id:row.event_author_id, evento_id:row.event_id}]->(p)'],
    ];

    public function __construct(private Neo4jClient $client) {}

    public function load(string $directory, int $concurrency = 1): array
    {
        if ($concurrency < 1 || $concurrency > 2) {
            throw new RuntimeException('A concorrência de carga deve estar entre 1 e 2 no piloto.');
        }
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $manifest = $this->readManifest($directory);
        $this->assertEmptyGraph();
        $batchSize = $manifest['spec']['batch_size'] ?? 1_000;
        if (! is_int($batchSize) || $batchSize < 1 || $batchSize > 10_000) {
            throw new RuntimeException('batch_size do manifesto deve estar entre 1 e 10000.');
        }

        $started = microtime(true);
        $tables = [];
        foreach (self::FILES as $file => $definition) {
            $tables[$file] = $this->loadFile(
                $directory.'/'.$file,
                $file,
                $definition['columns'],
                $definition['cypher'],
                $batchSize,
                $concurrency,
            );
            $key = pathinfo($file, PATHINFO_FILENAME);
            if ($tables[$file]['rows'] !== ($manifest['counts'][$key] ?? null)) {
                throw new RuntimeException("Contagem de {$file} divergiu do manifesto.");
            }
        }

        $actual = $this->counts('benchmark-corpus:count-check');
        $expected = $manifest['counts'];
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Contagens persistidas no Neo4j divergem do manifesto.');
        }

        return [
            'status' => 'succeeded',
            'architecture' => 'async',
            'seed' => $manifest['seed'] ?? null,
            'concurrency' => $concurrency,
            'runtime_mode' => \Hyperf\Coroutine\Coroutine::inCoroutine() ? 'swoole_coroutine' : 'blocking',
            'batch_size' => $batchSize,
            'counts' => $actual,
            'tables' => $tables,
            'duration_ms' => round((microtime(true) - $started) * 1_000, 3),
        ];
    }

    private function readManifest(string $directory): array
    {
        $path = $directory.'/manifest.json';
        if (! is_file($path)) {
            throw new RuntimeException('manifest.json não encontrado no corpus.');
        }
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['schema_version'] ?? null) !== 1 || ! is_array($manifest['counts'] ?? null)
            || ! is_array($manifest['files'] ?? null) || ! is_array($manifest['spec'] ?? null)) {
            throw new RuntimeException('Manifesto de corpus inválido ou incompatível.');
        }
        if (! is_int($manifest['spec']['feed_limit'] ?? null)
            || ($manifest['counts']['feed_items'] ?? PHP_INT_MAX) > $manifest['spec']['feed_limit']) {
            throw new RuntimeException('Manifesto excede o limite configurado de entradas de feed.');
        }

        foreach (self::FILES as $file => $_) {
            $filePath = $directory.'/'.$file;
            $expected = $manifest['files'][$file] ?? null;
            if (! is_file($filePath) || ! is_array($expected)
                || ! hash_equals((string) ($expected['sha256'] ?? ''), (string) hash_file('sha256', $filePath))) {
                throw new RuntimeException("Arquivo ausente ou SHA-256 divergente: {$file}.");
            }
            if ((int) ($expected['bytes'] ?? -1) !== filesize($filePath)) {
                throw new RuntimeException("Tamanho do arquivo diverge do manifesto: {$file}.");
            }
        }
        return $manifest;
    }

    private function assertEmptyGraph(): void
    {
        $counts = $this->counts('benchmark-corpus:empty-check');
        if (array_sum($counts) !== 0) {
            throw new RuntimeException('O Neo4j do benchmark não está vazio; recusando misturar corpora.');
        }
    }

    private function counts(string $marker): array
    {
        $rows = $this->client->run('// '.$marker.'
            CALL { MATCH (u:User) RETURN count(u) AS users }
            CALL { MATCH (p:Post) RETURN count(p) AS posts }
            CALL { MATCH ()-[f:FOLLOWS]->() RETURN count(f) AS follows }
            CALL { MATCH ()-[s:SHARED]->() RETURN count(s) AS shares }
            CALL { MATCH ()-[e:FEED]->() RETURN count(e) AS feed_items }
            CALL { MATCH (t:Task) RETURN count(t) AS tasks }
            RETURN users, posts, follows, shares, feed_items, tasks');
        $row = $rows[0] ?? null;
        if (! is_array($row)) {
            throw new RuntimeException('Neo4j não retornou contagens do corpus.');
        }
        $counts = [
            'users' => (int) ($row['users'] ?? -1),
            'posts' => (int) ($row['posts'] ?? -1),
            'follows' => (int) ($row['follows'] ?? -1),
            'shares' => (int) ($row['shares'] ?? -1),
            'feed_items' => (int) ($row['feed_items'] ?? -1),
        ];
        if (isset($row['tasks']) && (int) $row['tasks'] !== 0) {
            throw new RuntimeException('O Neo4j contém tarefas de distribuição pendentes.');
        }
        return $counts;
    }

    private function loadFile(
        string $path,
        string $file,
        array $columns,
        string $query,
        int $batchSize,
        int $concurrency,
    ): array
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            throw new RuntimeException("Não foi possível abrir {$file}.");
        }
        $started = microtime(true);
        $batchQuery = '// benchmark-corpus:'.pathinfo($file, PATHINFO_FILENAME)."\n".$query;
        $parallel = $concurrency === 1 ? null : new Parallel($concurrency);
        $pendingBatches = 0;
        $batchCount = 0;
        $batchCallDurations = [];
        $awaitBatches = function () use (&$parallel, &$pendingBatches, &$batchCallDurations, $concurrency): void {
            if ($parallel === null || $pendingBatches === 0) {
                return;
            }
            foreach ($parallel->wait() as $durationMs) {
                $batchCallDurations[] = $durationMs;
            }
            $parallel = new Parallel($concurrency);
            $pendingBatches = 0;
        };
        $submitBatch = function (array $rows) use (
            &$parallel,
            &$pendingBatches,
            &$batchCount,
            &$batchCallDurations,
            $batchQuery,
            $concurrency,
            $awaitBatches,
        ): void {
            $batchCount++;
            if ($parallel === null) {
                $started = hrtime(true);
                $this->client->run($batchQuery, ['rows' => $rows]);
                $batchCallDurations[] = (hrtime(true) - $started) / 1_000_000;
                return;
            }

            $parallel->add(function () use ($batchQuery, $rows): float {
                $started = hrtime(true);
                $this->client->run($batchQuery, ['rows' => $rows]);
                return (hrtime(true) - $started) / 1_000_000;
            }, $batchCount);
            $pendingBatches++;
            if ($pendingBatches === $concurrency) {
                $awaitBatches();
            }
        };
        try {
            if (fgetcsv($stream, null, ',', '"', '') !== $columns) {
                throw new RuntimeException('Cabeçalho CSV incompatível em '.$file.'.');
            }
            $batch = [];
            $count = 0;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if (count($values) !== count($columns)) {
                    throw new RuntimeException('Linha CSV inválida em '.$file.'.');
                }
                $batch[] = array_combine($columns, $values);
                $count++;
                if (count($batch) >= $batchSize) {
                    $submitBatch($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $submitBatch($batch);
            }
            $awaitBatches();
        } finally {
            fclose($stream);
        }
        $durationMs = round((microtime(true) - $started) * 1_000, 3);
        return [
            'rows' => $count,
            'batches' => $batchCount,
            'duration_ms' => $durationMs,
            'rows_per_second' => round($count / max($durationMs / 1_000, 0.001), 2),
            'batch_call_ms' => self::summarizeTimings($batchCallDurations),
        ];
    }

    private static function summarizeTimings(array $values): ?array
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $percentile = static function (float $quantile) use ($values): float {
            $index = (count($values) - 1) * $quantile;
            $low = (int) floor($index);
            $high = (int) ceil($index);
            return $values[$low] + ($values[$high] - $values[$low]) * ($index - $low);
        };

        return [
            'count' => count($values),
            'avg' => round(array_sum($values) / count($values), 3),
            'p50' => round($percentile(0.50), 3),
            'p95' => round($percentile(0.95), 3),
            'p99' => round($percentile(0.99), 3),
            'max' => round(max($values), 3),
        ];
    }
}
