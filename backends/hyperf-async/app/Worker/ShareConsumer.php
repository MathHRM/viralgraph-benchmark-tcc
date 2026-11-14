<?php
declare(strict_types=1);
namespace App\Worker;

use Hyperf\Amqp\ConnectionFactory;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Engine\Channel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consumer push (basic_consume) da fila `shares`, em corrotinas próprias no worker.
 * prefetch_count limita as mensagens não confirmadas em voo e define o tamanho máximo do lote.
 *
 * Duas corrotinas: a leitora só chama wait() (timeout de 1 s) e entrega cada mensagem num
 * canal Swoole; a agrupadora monta o lote (cheio ou FLUSH_MS após a primeira mensagem) e o
 * persiste. Timeouts curtos em wait() podiam expirar entre os frames de uma mesma entrega
 * (método → cabeçalho → corpo) e dessincronizar o canal AMQP ("Expecting 2, received frame
 * type 1"), deixando mensagens presas sem ACK; por isso o prazo do lote fica fora do wait().
 */
final class ShareConsumer
{
    private const READ_TIMEOUT = 1.0;

    public function __construct(private ConnectionFactory $connections, private ShareBatchProcessor $batches) {}

    public function run(callable $running): void
    {
        $prefetch = (int) (getenv('SHARE_PREFETCH') ?: 50);
        $flush = max(1, (int) (getenv('FLUSH_MS') ?: 5)) / 1000;
        while ($running()) {
            $channel = null;
            try {
                $channel = $this->connections->getConnection('default')->getChannel();
                $channel->queue_declare('shares', false, true, false, false);
                $channel->basic_qos(0, $prefetch, false);
                $inbox = new Channel($prefetch);
                $channel->basic_consume('shares', '', false, false, false, false,
                    function (AMQPMessage $message) use ($inbox) { $inbox->push($message); });
                Coroutine::create(function () use ($channel, $running, $inbox) {
                    try {
                        while ($running() && $channel->is_consuming()) {
                            try {
                                $channel->wait(null, false, self::READ_TIMEOUT);
                            } catch (AMQPTimeoutException) {
                            }
                        }
                    } catch (\Throwable $error) {
                        fwrite(STDERR, 'shares reader: '.$error->getMessage().PHP_EOL);
                    } finally {
                        $inbox->close();
                    }
                });
                $this->drain($inbox, $prefetch, $flush);
            } catch (\Throwable $error) {
                fwrite(STDERR, 'shares consumer: '.$error->getMessage().PHP_EOL);
                usleep(250_000);
            } finally {
                // Fechar o canal devolve à fila tudo o que ficou sem ACK.
                try { $channel?->close(); } catch (\Throwable) {}
            }
        }
    }

    /** Agrupa até $size mensagens ou $flush segundos após a primeira; termina quando o canal fecha. */
    private function drain(Channel $inbox, int $size, float $flush): void
    {
        while (true) {
            $first = $inbox->pop(self::READ_TIMEOUT);
            if ($first === false) {
                if ($inbox->isClosing()) {
                    return;
                }
                continue;
            }
            $batch = [$first];
            $deadline = microtime(true) + $flush;
            while (count($batch) < $size && ($left = $deadline - microtime(true)) > 0) {
                $message = $inbox->pop($left);
                if ($message === false) {
                    break;
                }
                $batch[] = $message;
            }
            foreach ($this->batches->process($batch) as $failed) {
                $failed->nack(true); // falha operacional: volta à fila, não se perde
            }
            touch('/app/runtime/worker-heartbeat');
        }
    }
}
