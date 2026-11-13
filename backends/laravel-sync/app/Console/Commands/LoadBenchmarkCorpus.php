<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LoadBenchmarkCorpus extends Command
{
    protected $signature = 'benchmark:load-corpus {directory : Diretório com CSVs e manifest.json} {--result= : Arquivo JSON externo para o resultado da carga}';

    protected $description = 'Carrega em lotes um corpus validado em um banco de benchmark vazio';

    private const FILES = [
        'users.csv' => ['table' => 'users', 'columns' => ['id', 'name', 'nickname', 'birth_date', 'created_at', 'updated_at', 'feed_seq']],
        'follows.csv' => ['table' => 'follows', 'columns' => ['id', 'follower_id', 'followed_id', 'created_at']],
        'posts.csv' => ['table' => 'posts', 'columns' => ['id', 'user_id', 'content', 'created_at']],
        'shares.csv' => ['table' => 'shares', 'columns' => ['id', 'user_id', 'post_id', 'created_at']],
        'feed_items.csv' => ['table' => 'feed_items', 'columns' => ['id', 'user_id', 'post_id', 'origin', 'event_author_id', 'event_id', 'seq']],
    ];

    public function handle(): int
    {
        try {
            if (getenv('BENCHMARK_MODE') !== '1') {
                throw new RuntimeException('Carga de corpus permitida apenas em BENCHMARK_MODE=1.');
            }
            $directory = rtrim((string) $this->argument('directory'), DIRECTORY_SEPARATOR);
            $manifest = $this->readAndValidateManifest($directory);
            $this->assertDatabaseEmpty();
            $batchSize = (int) ($manifest['spec']['batch_size'] ?? 1_000);
            if ($batchSize < 1 || $batchSize > 10_000) {
                throw new RuntimeException('batch_size do manifesto deve estar entre 1 e 10000.');
            }

            $started = microtime(true);
            $loaded = [];
            foreach (self::FILES as $file => $definition) {
                $loaded[$definition['table']] = $this->loadCsv(
                    $directory.'/'.$file,
                    $definition['table'],
                    $definition['columns'],
                    $batchSize,
                );
                $expected = $manifest['counts'][$definition['table']] ?? null;
                if ($loaded[$definition['table']]['rows'] !== $expected) {
                    throw new RuntimeException("Contagem de {$file} divergiu do manifesto.");
                }
            }

            $actual = [
                'users' => DB::table('users')->count(),
                'follows' => DB::table('follows')->count(),
                'posts' => DB::table('posts')->count(),
                'shares' => DB::table('shares')->count(),
                'feed_items' => DB::table('feed_items')->count(),
            ];
            $expectedCounts = $manifest['counts'];
            ksort($actual);
            ksort($expectedCounts);
            if ($actual !== $expectedCounts) {
                throw new RuntimeException('Contagens persistidas divergem do manifesto.');
            }

            $result = [
                'status' => 'succeeded',
                'architecture' => 'sync',
                'seed' => $manifest['seed'] ?? null,
                'counts' => $actual,
                'tables' => $loaded,
                'duration_ms' => round((microtime(true) - $started) * 1_000, 3),
            ];
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ($resultPath = $this->option('result')) {
                if (file_put_contents((string) $resultPath, $json."\n") === false) {
                    throw new RuntimeException('Não foi possível gravar o resultado da carga.');
                }
            }
            $this->line($json);

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->components->error($error->getMessage());
            return self::FAILURE;
        }
    }

    private function readAndValidateManifest(string $directory): array
    {
        $path = $directory.'/manifest.json';
        if (! is_file($path)) {
            throw new RuntimeException('manifest.json não encontrado no corpus.');
        }
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['schema_version'] ?? null) !== 1 || ! is_array($manifest['counts'] ?? null)
            || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('Manifesto de corpus inválido ou incompatível.');
        }

        foreach (self::FILES as $file => $definition) {
            $path = $directory.'/'.$file;
            $expected = $manifest['files'][$file] ?? null;
            if (! is_file($path) || ! is_array($expected)
                || ! hash_equals((string) ($expected['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
                throw new RuntimeException("Arquivo ausente ou SHA-256 divergente: {$file}.");
            }
            if ((int) ($expected['bytes'] ?? -1) !== filesize($path)) {
                throw new RuntimeException("Tamanho do arquivo diverge do manifesto: {$file}.");
            }
        }

        $expectedFeed = $manifest['spec']['feed_limit'] ?? null;
        if (! is_int($expectedFeed) || $manifest['counts']['feed_items'] > $expectedFeed) {
            throw new RuntimeException('Manifesto excede o limite configurado de entradas de feed.');
        }

        return $manifest;
    }

    private function assertDatabaseEmpty(): void
    {
        foreach (['users', 'follows', 'posts', 'shares', 'feed_items', 'views'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("A tabela {$table} não está vazia; recusando misturar corpora.");
            }
        }
    }

    private function loadCsv(string $path, string $table, array $columns, int $batchSize): array
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            throw new RuntimeException("Não foi possível abrir {$path}.");
        }

        $start = microtime(true);
        try {
            $header = fgetcsv($stream);
            if ($header !== $columns) {
                throw new RuntimeException('Cabeçalho CSV incompatível em '.basename($path).'.');
            }

            $batch = [];
            $count = 0;
            while (($values = fgetcsv($stream)) !== false) {
                if (count($values) !== count($columns)) {
                    throw new RuntimeException('Linha CSV inválida em '.basename($path).'.');
                }
                $row = array_combine($columns, $values);
                foreach (['feed_seq', 'seq'] as $integerColumn) {
                    if (array_key_exists($integerColumn, $row)) {
                        $row[$integerColumn] = (int) $row[$integerColumn];
                    }
                }
                $batch[] = $row;
                $count++;
                if (count($batch) >= $batchSize) {
                    DB::table($table)->insert($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                DB::table($table)->insert($batch);
            }
        } finally {
            fclose($stream);
        }

        return ['rows' => $count, 'duration_ms' => round((microtime(true) - $start) * 1_000, 3)];
    }
}
