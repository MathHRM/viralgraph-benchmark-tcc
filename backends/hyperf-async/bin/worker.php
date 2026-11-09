<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
define('SWOOLE_HOOK_FLAGS', Hyperf\Engine\DefaultOption::hookFlags());
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_FLAGS);
date_default_timezone_set('UTC');
Hyperf\Di\ClassLoader::init();
$container = require BASE_PATH.'/config/container.php';
Swoole\Coroutine\run(function () use ($container) {
    try {
        $container->get(App\Worker\Runner::class)->run();
    } finally {
        Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->resume();
    }
});
