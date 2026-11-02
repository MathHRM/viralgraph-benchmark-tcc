<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\APC;
use Symfony\Component\HttpFoundation\Response;

class RecordHttpMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('metrics.started_at', hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $isHealthCheck = $request->is(['api/health', 'health']) && ! $request->headers->has('X-Tcc-Load-Test');

        if ($request->is(['api/metrics', 'metrics']) || $isHealthCheck || ! extension_loaded('apcu') || ! apcu_enabled()) {
            return;
        }

        $route = $request->route();
        $routeName = $route?->uri() ?? 'unmatched';
        $labels = [$request->method(), $routeName, (string) $response->getStatusCode()];
        $duration = (hrtime(true) - (int) $request->attributes->get('metrics.started_at')) / 1_000_000_000;
        $registry = new CollectorRegistry(new APC);

        $registry->getOrRegisterCounter(
            'tcc',
            'http_requests_total',
            'Total number of HTTP requests completed by the application.',
            ['method', 'route', 'status_code'],
        )->incBy(1, $labels);

        $registry->getOrRegisterHistogram(
            'tcc',
            'http_request_duration_seconds',
            'Duration of completed HTTP requests in seconds.',
            ['method', 'route', 'status_code'],
            [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5],
        )->observe($duration, $labels);
    }
}
