<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\FeedRequest;
use App\Services\FeedService;
use Illuminate\Http\JsonResponse;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $service,
    ) {}

    public function index(FeedRequest $request, string $id): JsonResponse
    {
        return response()->json($this->service->page($id, $request->input('cursor')));
    }
}
