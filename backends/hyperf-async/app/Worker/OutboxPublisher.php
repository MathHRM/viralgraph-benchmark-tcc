<?php
declare(strict_types=1);
namespace App\Worker;

use App\Infrastructure\RabbitMQ\Broker;
use App\Repository\DistributionTaskRepository;

final class OutboxPublisher
{
    public function __construct(private DistributionTaskRepository $tasks) {}

    /** Retorna quantas tarefas pendentes foram lidas; marca em lote as já confirmadas, inclusive se uma publicação falhar. */
    public function publish(Broker $broker, int $size): int
    {
        $pending = $this->tasks->pending($size);
        $published = [];
        try {
            foreach ($pending as $task) {
                $broker->publish('distribution', $task);
                $published[] = $task['id'];
            }
        } finally {
            if ($published) {
                $this->tasks->markPublished($published);
            }
        }
        return count($pending);
    }
}
