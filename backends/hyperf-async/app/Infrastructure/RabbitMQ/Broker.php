<?php
declare(strict_types=1);
namespace App\Infrastructure\RabbitMQ;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Broker
{
    public readonly AMQPStreamConnection $connection;
    public readonly \PhpAmqpLib\Channel\AMQPChannel $channel;
    private bool $rejected = false;

    public function __construct()
    {
        $this->connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq', 5672,
            getenv('RABBITMQ_USER') ?: 'benchmark',
            getenv('RABBITMQ_PASSWORD') ?: 'benchmark-local',
            '/', false, 'AMQPLAIN', null, 'en_US', 3, 3, null, false, 0
        );
        $this->channel = $this->connection->channel();
        foreach (['posts', 'distribution'] as $queue) {
            $this->channel->queue_declare($queue, false, true, false, false);
        }
        $this->channel->confirm_select();
        $this->channel->set_nack_handler(function () { $this->rejected = true; });
        $this->channel->set_return_listener(function () { $this->rejected = true; });
    }

    public function publish(string $queue, array $payload): void
    {
        $this->rejected = false;
        $this->channel->basic_publish(new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]), '', $queue, true);
        $this->channel->wait_for_pending_acks_returns(3);
        if ($this->rejected) {
            throw new \RuntimeException('Publicação rejeitada pelo broker.');
        }
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
