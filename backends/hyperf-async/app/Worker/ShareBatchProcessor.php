<?php
declare(strict_types=1);
namespace App\Worker;

use App\Repository\ShareRepository;
use PhpAmqpLib\Message\AMQPMessage;

final class ShareBatchProcessor
{
    public function __construct(private ShareRepository $shares) {}

    /**
     * Um lote = uma transação Cypher. Se o commit falhar (ex.: timeout num lote com fan-out de
     * celebridade), divide o lote ao meio e tenta de novo. ACK somente depois do commit que
     * contém a mensagem. Devolve as mensagens que falharam mesmo isoladas (sem ACK).
     *
     * @param list<AMQPMessage> $messages
     * @return list<AMQPMessage>
     */
    public function process(array $messages): array
    {
        $payloads = [];
        foreach ($messages as $index => $message) {
            try {
                $payloads[] = json_decode($message->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $message->nack(false); // mensagem corrompida não volta à fila
                unset($messages[$index]);
            }
        }
        $messages = array_values($messages);
        if (!$messages) {
            return [];
        }
        try {
            $this->shares->persistBatch($payloads);
        } catch (\RuntimeException $error) {
            if (count($messages) === 1) {
                fwrite(STDERR, 'shares: '.$error->getMessage().PHP_EOL);
                return $messages;
            }
            $half = intdiv(count($messages), 2);
            return [...$this->process(array_slice($messages, 0, $half)), ...$this->process(array_slice($messages, $half))];
        }
        foreach ($messages as $message) {
            $message->ack();
        }
        return [];
    }
}
