<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
putenv('NEO4J_TIMEOUT_MS=30000');
$container = require BASE_PATH.'/config/container.php';
$container->get(App\Infrastructure\Neo4j\SchemaInitializer::class)->initialize();
echo "Constraints e contador inicializados.\n";
