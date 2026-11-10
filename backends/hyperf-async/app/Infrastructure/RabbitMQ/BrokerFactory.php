<?php
declare(strict_types=1);
namespace App\Infrastructure\RabbitMQ;

use Hyperf\Amqp\ConnectionFactory;

class BrokerFactory
{
    public function __construct(private ConnectionFactory $connections) {}

    // Cada operação recebe um canal exclusivo na conexão gerenciada pelo Hyperf.
    public function open(): Broker
    {
        return new Broker($this->connections->getConnection('default'));
    }
}
