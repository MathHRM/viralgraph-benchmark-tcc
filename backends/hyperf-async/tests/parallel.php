<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Exception\ApiError;
use App\Infrastructure\Neo4j\Neo4jClient;
use App\Infrastructure\Neo4j\Neo4jPool;
use App\Repository\FeedRepository;
use Hyperf\Coroutine\Parallel;

function check(bool $condition, string $message = 'Invariante do pool/paralelismo violada.'): void {
    if (!$condition) { throw new LogicException($message); }
}
function invalid(callable $call, int $status): void {
    try { $call(); } catch (ApiError $error) { check($error->status === $status, 'Status inesperado: '.$error->status); return; }
    throw new LogicException('Operação inválida foi aceita.');
}

/**
 * Stub que conta chamadas concorrentes a run() para evidenciar o paralelismo e respeita o
 * atraso de I/O com Coroutine::sleep. Distingue consultas de User, Post e feed para simular
 * ausências (404) de forma controlada.
 */
final class CountingClient extends Neo4jClient {
    public int $inFlight = 0;
    public int $maxInFlight = 0;
    public bool $userMissing = false;
    public bool $postMissing = false;
    public bool $feedUserMissing = false;
    /** @var list<array> Entradas devolvidas pela consulta de página do feed paralelo. */
    public array $feedEntries = [];
    public function __construct() {}
    public function run(string $query, array $params = []): array {
        $this->inFlight++;
        $this->maxInFlight = max($this->maxInFlight, $this->inFlight);
        \Swoole\Coroutine::sleep(0.02);
        try {
            if (str_contains($query, '-[e:FEED]->')) {
                return $this->feedEntries;
            }
            if (str_contains($query, ':User') && str_contains($query, 'RETURN u.id AS id')) {
                return $this->feedUserMissing ? [] : [['id' => $params['uid']]];
            }
            if (str_contains($query, ':User')) {
                return $this->userMissing ? [] : [['n' => ['id' => $params['id'] ?? 'u', 'nome' => 'N', 'apelido' => 'a', 'nascimento' => '2000-01-01']]];
            }
            if (str_contains($query, ':Post')) {
                return $this->postMissing ? [] : [['n' => ['usuario_id' => 'author']]];
            }
            return [['ok' => true]]; // ShareRepository::create e afins.
        } finally {
            $this->inFlight--;
        }
    }
}

// ---------------------------------------------------------------------------
// 1. Pool: backpressure limita a concorrência ao tamanho configurado.
// ---------------------------------------------------------------------------
\Swoole\Coroutine\run(function (): void {
    $pool = new Neo4jPool(2);
    check($pool->size() === 2, 'Tamanho do pool divergente.');
    check($pool->available() === 2, 'Pool deveria iniciar cheio.');

    $inFlight = 0;
    $maxInFlight = 0;
    $parallel = new Parallel();
    for ($i = 0; $i < 6; $i++) {
        $parallel->add(function () use ($pool, &$inFlight, &$maxInFlight): void {
            $pool->acquire();
            try {
                $inFlight++;
                $maxInFlight = max($maxInFlight, $inFlight);
                \Swoole\Coroutine::sleep(0.02);
                $inFlight--;
            } finally {
                $pool->release();
            }
        });
    }
    $parallel->wait();
    check($maxInFlight === 2, 'O pool não limitou a concorrência a 2: '.$maxInFlight);
    check($pool->available() === 2, 'Todas as permissões deveriam ter sido devolvidas.');
});
echo "Pool: backpressure limita a concorrência ao tamanho e devolve permissões: OK\n";

// Pool default (sem env) usa 32; fora de corrotina acquire/release são no-op transparentes.
check((new Neo4jPool())->size() === 32, 'Tamanho padrão do pool divergente.');
$noop = new Neo4jPool(1);
$noop->acquire();
$noop->acquire(); // Fora de corrotina não bloqueia nem contabiliza.
$noop->release();
check($noop->available() === 1, 'Fora de corrotina o pool não deve alterar as permissões.');
echo "Pool: padrão 32 e no-op fora de corrotina: OK\n";

// 2. ShareService: validação passou a ser uma única consulta Cypher (ver tests/services.php).

// ---------------------------------------------------------------------------
// 3. FeedRepository paralelo: existência + página simultâneas, saída idêntica ao contrato.
// ---------------------------------------------------------------------------
putenv('FEED_PARALLEL=true');
\Swoole\Coroutine\run(function (): void {
    $client = new CountingClient();
    $client->feedEntries = [
        ['entry' => ['post' => ['id' => 'p1'], 'origem' => 'post', 'autor_evento_id' => 'a', 'evento_id' => 'e1', 'seq' => 2]],
        ['entry' => ['post' => ['id' => 'p0'], 'origem' => 'post', 'autor_evento_id' => 'a', 'evento_id' => 'e0', 'seq' => 1]],
    ];
    $feed = new FeedRepository($client);
    $entries = $feed->page('reader', null);
    check(count($entries) === 2, 'Número de entradas do feed divergiu.');
    check($entries[0]['post']['id'] === 'p1' && $entries[0]['seq'] === 2, 'Mapeamento/ordem das entradas divergiu.');
    check($client->maxInFlight === 2, 'Existência e página não rodaram em paralelo: '.$client->maxInFlight);

    // Usuário inexistente => 404, independentemente da página.
    $missing = new CountingClient();
    $missing->feedUserMissing = true;
    invalid(fn () => (new FeedRepository($missing))->page('ghost', null), 404);
});
putenv('FEED_PARALLEL');
echo "FeedRepository: split paralelo com saída idêntica e 404 de usuário inexistente: OK\n";

echo "Pool e paralelismo (FeedRepository): OK\n";
