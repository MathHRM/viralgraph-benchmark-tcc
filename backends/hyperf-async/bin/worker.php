<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$size = (int) (getenv('BATCH_SIZE') ?: 100);
$flush = (int) (getenv('FLUSH_MS') ?: 5);
if ($size < 1 || $size > 1000 || $flush < 1 || $flush > 1000) {
    throw new RuntimeException('BATCH_SIZE ou FLUSH_MS inválido.');
}
$graph = new App\Graph();
$running = true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
while ($running) {
    $broker = null;
    try {
        $broker = new App\Broker();
        while ($running) {
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
            } while (count($messages) < $size && microtime(true) < $deadline && $running);
            if ($messages) {
                $graph->persistPosts(array_map(fn ($m) => json_decode($m->body, true, 512, JSON_THROW_ON_ERROR), $messages));
                foreach ($messages as $m) {
                    $m->ack();
                }
            }
            foreach ($graph->run('MATCH (t:Task {published:false, done:false}) RETURN t.id AS id LIMIT $size', ['size' => $size]) as $task) {
                $broker->publish('distribution', $task);
                $graph->run('MATCH (t:Task {id:$id}) SET t.published=true', $task);
            }
            $m = $broker->channel->basic_get('distribution');
            if ($m) {
                $task = json_decode($m->body, true, 512, JSON_THROW_ON_ERROR);
                do {
                    $done = $graph->distribute($task['id'], $size);
                    touch('/app/runtime/worker-heartbeat');
                } while (!$done && $running);
                if ($done) {
                    $m->ack();
                }
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage().PHP_EOL);
        usleep(250000);
    } finally {
        try { $broker?->close(); } catch (Throwable) {}
    }
}
