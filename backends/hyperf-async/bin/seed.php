<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';

if (getenv('BENCHMARK_MODE') !== '1') {
    fwrite(STDERR, "Carga de corpus permitida apenas em BENCHMARK_MODE=1.\n");
    exit(2);
}

$options = getopt('', ['directory:']);
$directory = $options['directory'] ?? null;
if (! is_string($directory) || $directory === '') {
    fwrite(STDERR, "Uso: php bin/seed.php --directory=/benchmark/corpus\n");
    exit(2);
}

$concurrency = getenv('BENCHMARK_LOAD_CONCURRENCY') ?: '1';
if (! in_array($concurrency, ['1', '2'], true)) {
    fwrite(STDERR, "BENCHMARK_LOAD_CONCURRENCY deve ser 1 ou 2 no piloto.\n");
    exit(2);
}
$concurrency = (int) $concurrency;

try {
    putenv('NEO4J_TIMEOUT_MS=30000');
    putenv('NEO4J_CURL_COROUTINE_HOOK=1');
    \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_CURL);
    $container = require BASE_PATH.'/config/container.php';
    \Swoole\Coroutine\run(function () use ($container, $directory, $concurrency, &$report): void {
        $report = $container->get(App\Infrastructure\Neo4j\CorpusLoader::class)
            ->load($directory, $concurrency);
    });
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
