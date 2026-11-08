<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
(new App\Graph())->initialize();
echo "Constraints e contador inicializados.\n";
