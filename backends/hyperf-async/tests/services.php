<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Exception\ApiError;
use App\Infrastructure\Neo4j\Neo4jClient;
use App\Infrastructure\RabbitMQ\Broker;
use App\Infrastructure\RabbitMQ\BrokerFactory;
use App\Repository\{UserRepository, PostRepository, FollowRepository, ShareRepository};
use App\Service\{UserService, FollowService, ShareService, PostService};

function check(bool $condition): void {
    if (!$condition) { throw new LogicException('Regra ou isolamento incorreto.'); }
}
function invalid(callable $call, int $status = 422): void {
    try { $call(); } catch (ApiError $error) { check($error->status === $status); return; }
    throw new LogicException('Operação inválida aceita.');
}
final class StubClient extends Neo4jClient {
    public array $queries = [];
    public array $next = [['n' => ['usuario_id' => 'author']]];
    public function run(string $query, array $params = []): array {
        $this->queries[] = [$query, $params];
        return $this->next;
    }
}
final class StubBroker extends Broker {
    public bool $closed = false;
    public array $payload = [];
    public function __construct(private bool $fail) {}
    public function publish(string $queue, array $payload): void {
        check($queue === 'posts');
        $this->payload = $payload;
        if ($this->fail) { throw new RuntimeException('Broker indisponível.'); }
    }
    public function close(): void { $this->closed = true; }
}
final class StubFactory extends BrokerFactory {
    public array $opened = [];
    public bool $fail = false;
    public function open(): Broker { return $this->opened[] = new StubBroker($this->fail); }
}
$client = new StubClient();
$users = new UserRepository($client);
$follow = new FollowService($users, new FollowRepository($client));
invalid(fn () => $follow->create(['seguidor_id' => 'a', 'seguido_id' => 'a']));
check(count($client->queries) === 2); // Consulta os recursos antes da regra de auto-relação.
$client->next = [];
invalid(fn () => $follow->create(['seguidor_id' => 'missing', 'seguido_id' => 'a']), 404);
$client->next = [['n' => ['usuario_id' => 'author']]];
$share = new ShareService($users, new PostRepository($client), new ShareRepository($client));
invalid(fn () => $share->create(['usuario_id' => 'author', 'post_id' => 'post']));
$client->next = [['n' => ['usuario_id' => 'another']]];
$result = $share->create(['usuario_id' => 'reader', 'post_id' => 'post']);
check($result['usuario_id'] === 'reader' && isset($result['id'], $result['criado_em']));
$client->queries = [];
$userService = new UserService($users);
invalid(fn () => $userService->create(['nome' => 'Pessoa', 'apelido' => 'nick', 'nascimento' => '2999-01-01']));
check($client->queries === []);
$factory = new StubFactory();
$posts = new PostService($users, $factory);
foreach ([false, false, true] as $fail) {
    $factory->fail = $fail;
    try {
        $id = $posts->create(['usuario_id' => 'author', 'conteudo' => 'texto']);
        check(!$fail && end($factory->opened)->payload['id'] === $id);
    } catch (RuntimeException) { check($fail); }
    check(end($factory->opened)->closed);
}
check(count($factory->opened) === 3 && $factory->opened[0] !== $factory->opened[1]);
echo "Regras de negócio, precedência e conexão por operação: OK\n";
