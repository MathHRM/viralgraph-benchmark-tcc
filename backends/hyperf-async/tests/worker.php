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
use App\Worker\ShareBatchProcessor;
use App\Repository\ShareRepository;
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
    public bool $requeued = false;
    public function ack($multiple = false): void { $this->acked = true; }
    public function nack($requeue = false, $multiple = false): void { $this->requeued = $requeue; }
};
$feeds = new FeedDistributor(new DistributionTaskRepository($client));
$client->rows = [['done' => false]];
$feeds->process($distribution, 100, fn () => false);
check(!$distribution->acked && $distribution->requeued); // Fatia ou encerramento antes do último lote devolve a tarefa à fila.
$client->rows = [['done' => true]];
$feeds->process($distribution, 100, fn () => true);
check($distribution->acked);
// Lote de compartilhamentos: uma transação UNWIND; falha divide o lote e só a mensagem ruim fica sem ACK.
final class FlakyShares extends ShareRepository {
    public array $calls = [];
    public function __construct() {}
    public function persistBatch(array $shares): array {
        $this->calls[] = array_column($shares, 'id');
        if (in_array('bad', array_column($shares, 'id'), true)) { throw new RuntimeException('timeout'); }
        return [];
    }
}
$shareMessage = fn (string $id) => new class(json_encode(['id' => $id])) extends AMQPMessage {
    public int $acks = 0;
    public ?bool $requeue = null;
    public function ack($multiple = false): void { $this->acks++; }
    public function nack($requeue = false, $multiple = false): void { $this->requeue = $requeue; }
};
$flaky = new FlakyShares();
$batchMessages = array_map($shareMessage, ['a', 'b', 'bad', 'c']);
$corrupt = new class('{') extends AMQPMessage {
    public ?bool $requeue = null;
    public function nack($requeue = false, $multiple = false): void { $this->requeue = $requeue; }
};
$failed = (new ShareBatchProcessor($flaky))->process([...$batchMessages, $corrupt]);
check($flaky->calls[0] === ['a', 'b', 'bad', 'c']);
check($failed === [$batchMessages[2]] && $batchMessages[2]->acks === 0);
check($batchMessages[0]->acks === 1 && $batchMessages[1]->acks === 1 && $batchMessages[3]->acks === 1);
check($corrupt->requeue === false);
echo "ACK após commit, outbox após confirmação e retomada de distribuição: OK\n";
