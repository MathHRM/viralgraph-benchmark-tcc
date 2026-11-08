<?php
declare(strict_types=1);
namespace App\Infrastructure\RabbitMQ;

class BrokerFactory
{
    // A conexão mutável pertence à operação; o container não deve compartilhá-la entre corrotinas.
    public function open(): Broker
    {
        return new Broker();
    }
}
