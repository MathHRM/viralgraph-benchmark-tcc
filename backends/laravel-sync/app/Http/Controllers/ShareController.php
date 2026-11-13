<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreShareRequest;
use App\Services\ShareService;
use Illuminate\Http\JsonResponse;

class ShareController extends Controller
{
    public function __construct(
        private readonly ShareService $service,
    ) {}

    public function create(StoreShareRequest $request): JsonResponse
    {
        $share = $this->service->create(
            $request->string('usuario_id')->toString(),
            $request->string('post_id')->toString(),
        );

        return response()->json([
            'id' => $share->id,
            'usuario_id' => $share->user_id,
            'post_id' => $share->post_id,
            'criado_em' => $share->created_at->utc()->toRfc3339String(),
        ], 201);
    }
}
