<?php
declare(strict_types=1);
namespace App\Worker;

use App\Infrastructure\RabbitMQ\BrokerFactory;

final class Runner
{
    private bool $running = true;

    public function __construct(
        private BrokerFactory $brokers,
        private PostBatchProcessor $batches,
        private OutboxPublisher $outbox,
        private FeedDistributor $feeds,
    ) {}

    public function run(): void
    {
        $size = (int) (getenv('BATCH_SIZE') ?: 100);
        $flush = (int) (getenv('FLUSH_MS') ?: 5);
        if ($size < 1 || $size > 1000 || $flush < 1 || $flush > 1000) {
            throw new \RuntimeException('BATCH_SIZE ou FLUSH_MS inválido.');
        }
        $this->running = true;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () { $this->running = false; });
        pcntl_signal(SIGINT, function () { $this->running = false; });
        while ($this->running) {
            $broker = null;
            try {
                $broker = $this->brokers->open();
                while ($this->running) {
                    touch('/app/runtime/worker-heartbeat');
                    $messages = [];
                    $deadline = microtime(true) + $flush / 1000;
                    do {
                        $m = $broker->channel->basic_get('posts');
                        if ($m) {
                            $messages[] = $m;
                        } elseif (microtime(true) < $deadline) {
                            usleep(500);
                        }
                    } while (count($messages) < $size && microtime(true) < $deadline && $this->running);
                    if ($messages) {
                        $this->batches->process($messages);
                    }
                    $this->outbox->publish($broker, $size);
                    $message = $broker->channel->basic_get('distribution');
                    if ($message) {
                        $this->feeds->process($message, $size, fn () => $this->running);
                    }
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, $e->getMessage().PHP_EOL);
                usleep(250000);
            } finally {
                try { $broker?->close(); } catch (\Throwable) {}
            }
        }
    }
}
