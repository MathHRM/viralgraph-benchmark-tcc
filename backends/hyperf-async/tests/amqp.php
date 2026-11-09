<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
define('SWOOLE_HOOK_FLAGS', Hyperf\Engine\DefaultOption::hookFlags());
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_FLAGS);
Hyperf\Di\ClassLoader::init();
$container = require BASE_PATH.'/config/container.php';
function check(bool $condition): void {
    if (!$condition) { throw new LogicException('Isolamento ou confirmação AMQP incorreto.'); }
}
Swoole\Coroutine\run(function () use ($container) {
    $connection = null;
    $first = $second = null;
    try {
        $connections = $container->get(Hyperf\Amqp\ConnectionFactory::class);
        $brokers = $container->get(App\Infrastructure\RabbitMQ\BrokerFactory::class);
        $connection = $connections->getConnection('default');
        $first = $brokers->open();
        $second = $brokers->open();
        check($first->channel !== $second->channel);
        check($connection === $connections->getConnection('default'));
        $first->close();
        $first = null;
        check($connection->isConnected() && $second->channel->is_open());
        // Fila exclusiva de teste, sem alimentar os consumidores da aplicação.
        [$queue] = $second->channel->queue_declare('', false, false, true, true);
        $second->publish($queue, ['id' => 'confirmed']);
        $message = $second->channel->basic_get($queue);
        check($message !== null && json_decode($message->body, true)['id'] === 'confirmed');
        $message->ack();
        try {
            $second->publish($queue.'-missing', ['id' => 'unroutable']);
            throw new LogicException('Mensagem sem destino foi aceita.');
        } catch (RuntimeException $error) {
            check($error->getMessage() === 'Publicação rejeitada pelo broker.');
        }
        // Um retorno negativo não deve contaminar a próxima confirmação.
        $second->publish($queue, ['id' => 'after-return']);
        $message = $second->channel->basic_get($queue);
        check($message !== null && json_decode($message->body, true)['id'] === 'after-return');
        $message->ack();
        $second->close();
        $second = null;
        check($connection->isConnected());
        echo "Conexão compartilhada, canais isolados, confirmação e roteamento obrigatório: OK\n";
    } finally {
        try { $first?->close(); } catch (Throwable) {}
        try { $second?->close(); } catch (Throwable) {}
        Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->resume();
    }
});
