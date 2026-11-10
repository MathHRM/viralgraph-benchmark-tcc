<?php
declare(strict_types=1);
return [
    // O consumo em lotes é executado pelo worker dedicado, sem consumidores automáticos.
    'enable' => false,
    'default' => [
        'host' => getenv('RABBITMQ_HOST') ?: 'rabbitmq',
        'port' => 5672,
        'user' => getenv('RABBITMQ_USER') ?: 'benchmark',
        'password' => getenv('RABBITMQ_PASSWORD') ?: 'benchmark-local',
        'vhost' => '/',
        'pool' => ['connections' => 1],
        'params' => [
            'connection_timeout' => 3,
            'read_write_timeout' => 20,
            'heartbeat' => 10,
            'channel_rpc_timeout' => 3.0,
            'keepalive' => true,
        ],
    ],
];
