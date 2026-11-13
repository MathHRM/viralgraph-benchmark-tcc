<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreFollowRequest;
use App\Services\FollowService;
use Illuminate\Http\JsonResponse;

class FollowController extends Controller
{
    public function __construct(
        private readonly FollowService $service,
    ) {}

    public function create(StoreFollowRequest $request): JsonResponse
    {
        $follow = $this->service->create(
            $request->string('seguidor_id')->toString(),
            $request->string('seguido_id')->toString(),
        );

        return response()->json([
            'id' => $follow->id,
            'seguidor_id' => $follow->follower_id,
            'seguido_id' => $follow->followed_id,
            'criado_em' => $follow->created_at->utc()->toRfc3339String(),
        ], 201);
    }
}
