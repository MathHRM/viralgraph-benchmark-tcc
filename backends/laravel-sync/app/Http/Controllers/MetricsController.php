<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    public function __construct(
        private readonly MetricsService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->service->index());
    }
}
