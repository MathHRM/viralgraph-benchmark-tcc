<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
define('SWOOLE_HOOK_FLAGS', Hyperf\Engine\DefaultOption::hookFlags());
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_FLAGS);
Hyperf\Di\ClassLoader::init();
$container = require BASE_PATH.'/config/container.php';
Swoole\Coroutine\run(function () use ($container, $argv) {
    try {
        $graph = new App\Infrastructure\Neo4j\Neo4jClient();
        switch ($argv[1]) {
            case 'commit-without-ack':
                $broker = $container->get(App\Infrastructure\RabbitMQ\BrokerFactory::class)->open();
                $message = $broker->channel->basic_get('posts');
                if (!$message) { throw new RuntimeException('Mensagem esperada.'); }
                $post = json_decode($message->body, true, 512, JSON_THROW_ON_ERROR);
                (new App\Repository\PostRepository($graph))->persistPosts([$post]);
                // Simula morte depois do commit e antes do ACK: close devolve à fila.
                $broker->close();
                echo json_encode($post);
                break;
            case 'replay':
                $broker = $container->get(App\Infrastructure\RabbitMQ\BrokerFactory::class)->open();
                $broker->publish('posts', json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR));
                $broker->close();
                break;
            case 'origins':
                echo json_encode($graph->run('MATCH (o:Origin {usuario_id:$user,post_id:$post}) RETURN count(o) AS total',
                    ['user' => $argv[2], 'post' => $argv[3]])[0]);
                break;
        }
    } finally {
        Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->resume();
    }
});
