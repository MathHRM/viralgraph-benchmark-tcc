<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
$container = require BASE_PATH.'/config/container.php';
$container->get(App\Worker\Runner::class)->run();
