<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShowPostRequest;
use App\Http\Requests\StoreShareRequest;
use App\Services\ShareService;
use Illuminate\Http\JsonResponse;

class ShareController extends Controller
{
    public function __construct(
        private readonly ShareService $service,
    ) {}

    public function create(StoreShareRequest $request, string $id): JsonResponse
    {
        $share = $this->service->create(
            $id,
            $request->string('usuario_id')->toString(),
            $request->has('origem_usuario_id') ? $request->string('origem_usuario_id')->toString() : null,
        );

        return response()->json([
            'id' => $share->id,
            'usuario_id' => $share->user_id,
            'post_id' => $share->post_id,
            'origem_usuario_id' => $share->source_user_id,
            'criado_em' => $share->created_at->utc()->toRfc3339String(),
        ], 201);
    }

    public function propagationTree(ShowPostRequest $request, string $id): JsonResponse
    {
        return response()->json($this->service->propagationTree($id));
    }
}
