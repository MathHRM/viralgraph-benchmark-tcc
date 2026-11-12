<?php
declare(strict_types=1);
namespace App\Worker;

use App\Repository\DistributionTaskRepository;
use PhpAmqpLib\Message\AMQPMessage;

final class FeedDistributor
{
    public function __construct(private DistributionTaskRepository $tasks) {}

    public function process(AMQPMessage $message, int $size, callable $running): void
    {
        $task = json_decode($message->body, true, 512, JSON_THROW_ON_ERROR);
        do {
            $done = $this->tasks->distribute($task['id'], $size);
            touch('/app/runtime/worker-heartbeat');
        } while (!$done && $running());
        if ($done) {
            $message->ack();
        } else {
            // Fatia encerrada ou desligamento: cursor já confirmado, tarefa volta à fila sem bloquear posts.
            $message->nack(true);
        }
    }
}
