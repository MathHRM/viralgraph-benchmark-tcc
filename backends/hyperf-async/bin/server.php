<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
define('SWOOLE_HOOK_FLAGS', Hyperf\Engine\DefaultOption::hookFlags());
date_default_timezone_set('UTC');
Hyperf\Di\ClassLoader::init();
$container = require BASE_PATH.'/config/container.php';
$container->get(Hyperf\Contract\ApplicationInterface::class)->run();
