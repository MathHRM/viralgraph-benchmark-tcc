<?php
declare(strict_types=1);
namespace App\Infrastructure\RabbitMQ;

use Hyperf\Amqp\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Broker
{
    public const QUEUES = ['posts', 'distribution', 'shares'];

    public readonly \PhpAmqpLib\Channel\AMQPChannel $channel;
    private bool $rejected = false;
    private bool $confirmed = false;

    public function __construct(AMQPConnection $connection)
    {
        // O canal é exclusivo da operação; a conexão pertence ao Hyperf.
        $this->channel = $connection->getConfirmChannel();
        try {
            foreach (self::QUEUES as $queue) {
                $this->channel->queue_declare($queue, false, true, false, false);
            }
            $this->channel->set_ack_handler(function () { $this->confirmed = true; });
            $this->channel->set_nack_handler(function () { $this->rejected = true; });
            $this->channel->set_return_listener(function () { $this->rejected = true; });
        } catch (\Throwable $error) {
            try { $this->channel->close(); } catch (\Throwable) {}
            throw $error;
        }
    }

    public function publish(string $queue, array $payload): void
    {
        $this->rejected = false;
        $this->confirmed = false;
        $this->channel->basic_publish(new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]), '', $queue, true);
        $this->channel->wait_for_pending_acks_returns(3);
        if ($this->rejected || !$this->confirmed) {
            throw new \RuntimeException('Publicação rejeitada pelo broker.');
        }
    }

    public function close(): void
    {
        $this->channel->close();
    }
}
