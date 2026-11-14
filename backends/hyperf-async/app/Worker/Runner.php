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
        private ShareConsumer $shares,
    ) {}

    public function run(): void
    {
        $size = (int) (getenv('BATCH_SIZE') ?: 100);
        $flush = (int) (getenv('FLUSH_MS') ?: 5);
        $slice = (int) (getenv('DISTRIBUTION_SLICE_MS') ?: 50);
        if ($size < 1 || $size > 1000 || $flush < 1 || $flush > 1000 || $slice < 1 || $slice > 10000) {
            throw new \RuntimeException('BATCH_SIZE, FLUSH_MS ou DISTRIBUTION_SLICE_MS inválido.');
        }
        $this->running = true;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () { $this->running = false; });
        pcntl_signal(SIGINT, function () { $this->running = false; });
        // Compartilhamentos: consumer push em corrotina própria, concorrente ao loop de posts.
        \Hyperf\Coroutine\Coroutine::create(fn () => $this->shares->run(fn () => $this->running));
        while ($this->running) {
            $broker = null;
            try {
                $broker = $this->brokers->open();
                $outboxAt = 0.0;
                $outboxFull = true;
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
                    // Outbox após novos posts, página cheia ou a cada 50 ms (compartilhamentos gravados pelas APIs).
                    if ($messages || $outboxFull || microtime(true) - $outboxAt > 0.05) {
                        $outboxFull = $this->outbox->publish($broker, $size) >= $size;
                        $outboxAt = microtime(true);
                    }
                    $message = $broker->channel->basic_get('distribution');
                    if ($message) {
                        $until = microtime(true) + $slice / 1000;
                        $this->feeds->process($message, $size, fn () => $this->running && microtime(true) < $until);
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
