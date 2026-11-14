<?php
declare(strict_types=1);
use Hyperf\Server\Event;
use Hyperf\Server\Server;
return [
    'mode' => SWOOLE_BASE,
    'servers' => [[
        'name' => 'http', 'type' => Server::SERVER_HTTP, 'host' => '0.0.0.0', 'port' => 9501,
        'sock_type' => SWOOLE_SOCK_TCP,
        'callbacks' => [Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest']],
    ]],
    'settings' => [
        'worker_num' => 1, 'enable_coroutine' => true,
        // HOOK_ALL: cURL (Neo4j), sockets (RabbitMQ) e sleep viram I/O de corrotina.
        'hook_flags' => SWOOLE_HOOK_ALL,
        'pid_file' => BASE_PATH.'/runtime/hyperf.pid',
        'max_coroutine' => 6144, 'max_request' => 100000,
        'open_tcp_nodelay' => true,
    ],
    'callbacks' => [
        Event::ON_WORKER_START => [Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
        Event::ON_WORKER_EXIT => [Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],
    ],
];
