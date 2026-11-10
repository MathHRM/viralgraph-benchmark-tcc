<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
$container = require BASE_PATH.'/config/container.php';
$container->get(App\Infrastructure\Neo4j\SchemaInitializer::class)->initialize();
echo "Constraints e contador inicializados.\n";
