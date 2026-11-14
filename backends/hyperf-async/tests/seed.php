<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Infrastructure\Neo4j\CorpusLoader;
use App\Infrastructure\Neo4j\Neo4jClient;

function seedCheck(bool $condition, string $message): void {
    if (!$condition) { throw new LogicException($message); }
}

final class SeedStubClient extends Neo4jClient {
    public array $queries = [];
    public int $batchLimit = 0;
    public function run(string $query, array $params = []): array {
        $this->queries[] = [$query, $params];
        if (str_contains($query, 'benchmark-corpus:empty-check')) {
            return [['users' => 0, 'posts' => 0, 'follows' => 0, 'shares' => 0, 'feed_items' => 0, 'tasks' => 0]];
        }
        if (str_contains($query, 'benchmark-corpus:count-check')) {
            return [['users' => 6, 'posts' => 30, 'follows' => 30, 'shares' => 30,
                'feed_items' => 150, 'tasks' => 0]];
        }
        if (isset($params['rows'])) {
            $this->batchLimit = max($this->batchLimit, count($params['rows']));
        }
        return [];
    }
}

final class ConcurrentSeedStubClient extends Neo4jClient {
    public int $inFlight = 0;
    public int $maxInFlight = 0;
    public function run(string $query, array $params = []): array {
        if (str_contains($query, 'benchmark-corpus:empty-check')) {
            return [['users' => 0, 'posts' => 0, 'follows' => 0, 'shares' => 0, 'feed_items' => 0, 'tasks' => 0]];
        }
        if (str_contains($query, 'benchmark-corpus:count-check')) {
            return [['users' => 6, 'posts' => 30, 'follows' => 30, 'shares' => 30,
                'feed_items' => 150, 'tasks' => 0]];
        }
        if (isset($params['rows'])) {
            $this->inFlight++;
            $this->maxInFlight = max($this->maxInFlight, $this->inFlight);
            \Swoole\Coroutine::sleep(0.02);
            $this->inFlight--;
        }
        return [];
    }
}

function writeCsv(string $path, array $header, array $rows): array {
    $stream = fopen($path, 'w');
    fputcsv($stream, $header);
    foreach ($rows as $row) { fputcsv($stream, $row); }
    fclose($stream);
    return ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
}

$directory = sys_get_temp_dir().'/hyperf-corpus-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$users = [];
$posts = [];
$follows = [];
$shares = [];
$feed = [];
for ($i = 0; $i < 6; $i++) {
    $users[] = [sprintf('00000000-0000-4000-8000-%012d', $i + 1), 'Pessoa '.$i,
        'seed-u'.$i, '2000-01-15', '2026-11-08 00:00:00', '2026-11-08 00:00:00', 25];
}
for ($i = 0; $i < 30; $i++) {
    $posts[] = [sprintf('10000000-0000-4000-8000-%012d', $i + 1), $users[$i % 6][0],
        'viral seed post '.$i, '2026-11-08 00:00:00'];
}
foreach ($users as $u => $user) {
    foreach ($users as $v => $followed) {
        if ($u !== $v) {
            $follows[] = [sprintf('20000000-0000-4000-8000-%012d', count($follows) + 1),
                $user[0], $followed[0], '2026-11-08 00:00:00'];
        }
    }
    $seq = 0;
    foreach ($posts as $post) {
        if ($post[1] !== $user[0]) {
            $seq++;
            $feed[] = [sprintf('30000000-0000-4000-8000-%012d', count($feed) + 1), $user[0],
                $post[0], 'post', $post[1], $post[0], $seq];
            if ($seq <= 5) {
                $shares[] = [sprintf('40000000-0000-4000-8000-%012d', count($shares) + 1),
                    $user[0], $post[0], '2026-11-08 00:00:00'];
            }
        }
    }
}
$files = [
    'users.csv' => [['id', 'name', 'nickname', 'birth_date', 'created_at', 'updated_at', 'feed_seq'], $users],
    'posts.csv' => [['id', 'user_id', 'content', 'created_at'], $posts],
    'follows.csv' => [['id', 'follower_id', 'followed_id', 'created_at'], $follows],
    'shares.csv' => [['id', 'user_id', 'post_id', 'created_at'], $shares],
    'feed_items.csv' => [['id', 'user_id', 'post_id', 'origin', 'event_author_id', 'event_id', 'seq'], $feed],
];
$counts = [];
$hashes = [];
foreach ($files as $name => [$header, $rows]) {
    $hashes[$name] = writeCsv($directory.'/'.$name, $header, $rows);
    $counts[pathinfo($name, PATHINFO_FILENAME)] = count($rows);
}
file_put_contents($directory.'/manifest.json', json_encode([
    'schema_version' => 1, 'seed' => 7,
    'spec' => ['users' => 6, 'posts' => 30, 'follows' => 30, 'shares' => 30,
        'feed_limit' => 250, 'batch_size' => 4],
    'counts' => $counts, 'files' => $hashes,
], JSON_THROW_ON_ERROR));

$client = new SeedStubClient();
$report = (new CorpusLoader($client))->load($directory);
seedCheck($report['status'] === 'succeeded', 'A carga não concluiu.');
seedCheck($report['counts']['feed_items'] === 150, 'Contagem de feed incorreta.');
seedCheck($report['concurrency'] === 1, 'A carga padrão deve permanecer sequencial.');
seedCheck($report['runtime_mode'] === 'blocking', 'O modo de execução síncrono foi reportado incorretamente.');
seedCheck($report['tables']['feed_items.csv']['batches'] === 38,
    'A quantidade de lotes do feed está incorreta.');
seedCheck(isset($report['tables']['feed_items.csv']['batch_call_ms'])
    && $report['tables']['feed_items.csv']['batch_call_ms']['count'] === 38,
    'As latências individuais dos lotes do feed não foram registradas.');
seedCheck($client->batchLimit <= 4, 'O carregador excedeu o tamanho do lote configurado.');
$labels = array_values(array_filter(array_map(
    static fn (array $entry): ?string => preg_match('/benchmark-corpus:(users|posts|follows|shares|feed_items)/', $entry[0], $m) ? $m[1] : null,
    $client->queries,
)));
seedCheck(array_values(array_unique($labels)) === ['users', 'posts', 'follows', 'shares', 'feed_items'],
    'Ordem de carga incorreta: '.json_encode(array_values(array_unique($labels))));
echo "Carga por lotes, validação do manifesto e ordem de dependências: OK\n";

\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_CURL);
$sequentialCoroutineClient = new ConcurrentSeedStubClient();
\Swoole\Coroutine\run(function () use ($directory, $sequentialCoroutineClient): void {
    $sequentialReport = (new CorpusLoader($sequentialCoroutineClient))->load($directory, 1);
    seedCheck($sequentialReport['status'] === 'succeeded', 'A carga sequencial em coroutine não concluiu.');
    seedCheck($sequentialReport['concurrency'] === 1, 'A concorrência sequencial reportada divergiu.');
    seedCheck($sequentialReport['runtime_mode'] === 'swoole_coroutine',
        'A execução sequencial não usou o runtime Swoole.');
    seedCheck($sequentialCoroutineClient->maxInFlight === 1,
        'O caminho sequencial dentro do runtime Swoole não permaneceu serial.');
    seedCheck($sequentialReport['tables']['feed_items.csv']['batch_call_ms']['count'] === 38,
        'As chamadas sequenciais ao Neo4j não foram cronometradas.');
});
echo "Carga sequencial dentro do runtime Swoole: OK\n";

$parallelClient = new ConcurrentSeedStubClient();
\Swoole\Coroutine\run(function () use ($directory, $parallelClient): void {
    $parallelReport = (new CorpusLoader($parallelClient))->load($directory, 2);
    seedCheck($parallelReport['status'] === 'succeeded', 'A carga concorrente não concluiu.');
    seedCheck($parallelReport['counts']['feed_items'] === 150, 'Contagem concorrente de feed incorreta.');
    seedCheck($parallelReport['concurrency'] === 2, 'A concorrência concorrente reportada divergiu.');
    seedCheck($parallelReport['runtime_mode'] === 'swoole_coroutine',
        'A execução concorrente não usou o runtime Swoole.');
    seedCheck($parallelClient->maxInFlight === 2,
        'A concorrência esperada de dois lotes não foi observada: '.$parallelClient->maxInFlight);
    seedCheck($parallelReport['tables']['feed_items.csv']['batch_call_ms']['count'] === 38,
        'As chamadas concorrentes ao Neo4j não foram cronometradas.');
});
echo "Carga concorrente limitada a dois lotes no mesmo runtime Swoole: OK\n";
