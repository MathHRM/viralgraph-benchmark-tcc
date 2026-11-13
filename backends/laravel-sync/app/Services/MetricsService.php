<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MetricsRepository;

class MetricsService
{
    public function __construct(private readonly MetricsRepository $metrics) {}

    /** @return array<string, int> */
    public function index(): array
    {
        return $this->metrics->logicalCreationCounts();
    }
}
