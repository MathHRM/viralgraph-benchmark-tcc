<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Infrastructure\Neo4j\Neo4jClient;
use App\Infrastructure\RabbitMQ\Broker;
use App\Repository\PostRepository;
use App\Repository\DistributionTaskRepository;
use App\Worker\PostBatchProcessor;
use App\Worker\OutboxPublisher;
use App\Worker\FeedDistributor;
use PhpAmqpLib\Message\AMQPMessage;

function check(bool $condition): void {
    if (!$condition) { throw new RuntimeException('Ordem de confirmação incorreta.'); }
}
final class RecordingClient extends Neo4jClient {
    public array $queries = [];
    public bool $fail = false;
    public array $rows = [['id' => 'task']];
    public function run(string $query, array $params = []): array {
        $this->queries[] = $query;
        if ($this->fail) { throw new RuntimeException('Commit recusado.'); }
        return $this->rows;
    }
}
final class RecordingBroker extends Broker {
    public bool $fail = false;
    public array $published = [];
    public function __construct() {}
    public function publish(string $queue, array $payload): void {
        if ($this->fail) { throw new RuntimeException('Confirmação recusada.'); }
        $this->published[] = [$queue, $payload];
    }
}
$client = new RecordingClient();
$batch = new PostBatchProcessor(new PostRepository($client));
$message = new class {
    public string $body = '{"id":"post"}';
    public bool $acked = false;
    public function ack(): void { $this->acked = true; }
};
$client->fail = true;
try { $batch->process([$message]); throw new LogicException('Falha não propagada.'); }
catch (RuntimeException) {}
check(!$message->acked);
$client->fail = false;
$batch->process([$message]);
check($message->acked);
$client->queries = [];
$outbox = new OutboxPublisher(new DistributionTaskRepository($client));
$broker = new RecordingBroker();
$broker->fail = true;
try { $outbox->publish($broker, 100); throw new LogicException('Falha não propagada.'); }
catch (RuntimeException) {}
check(count($client->queries) === 1);
$client->queries = [];
$broker->fail = false;
$outbox->publish($broker, 100);
check(count($client->queries) === 2 && $broker->published === [['distribution', ['id' => 'task']]]);
$message->acked = false;
$message->body = '{';
try { $batch->process([$message]); throw new LogicException('JSON inválido aceito.'); }
catch (JsonException) {}
check(!$message->acked);
$distribution = new class('{"id":"task"}') extends AMQPMessage {
    public bool $acked = false;
    public function ack($multiple = false): void { $this->acked = true; }
};
$feeds = new FeedDistributor(new DistributionTaskRepository($client));
$client->rows = [['done' => false]];
$feeds->process($distribution, 100, fn () => false);
check(!$distribution->acked); // Encerramento antes do último lote devolve a tarefa à fila.
$client->rows = [['done' => true]];
$feeds->process($distribution, 100, fn () => true);
check($distribution->acked);
echo "ACK após commit, outbox após confirmação e retomada de distribuição: OK\n";
