<?php
declare(strict_types=1);
namespace App\Worker;

use App\Infrastructure\RabbitMQ\Broker;
use App\Repository\DistributionTaskRepository;

final class OutboxPublisher
{
    public function __construct(private DistributionTaskRepository $tasks) {}

    public function publish(Broker $broker, int $size): void
    {
        foreach ($this->tasks->pending($size) as $task) {
            $broker->publish('distribution', $task);
            $this->tasks->markPublished($task);
        }
    }
}
