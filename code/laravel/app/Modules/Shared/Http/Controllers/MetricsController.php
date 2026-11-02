<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use Illuminate\Http\Response;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APC;

class MetricsController
{
    public function __invoke(): Response
    {
        if (! extension_loaded('apcu') || ! apcu_enabled()) {
            return response('APCu is required for shared PHP-FPM metrics storage.', 503);
        }

        $registry = new CollectorRegistry(new APC);
        $renderer = new RenderTextFormat;

        return response($renderer->render($registry->getMetricFamilySamples()))
            ->header('Content-Type', RenderTextFormat::MIME_TYPE);
    }
}
