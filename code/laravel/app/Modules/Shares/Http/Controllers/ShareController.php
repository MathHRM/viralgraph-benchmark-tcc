<?php

namespace App\Modules\Shares\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationService;
use App\Modules\Shares\Services\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShareController extends Controller
{
    public function store(Request $request, ShareService $shares, OperationService $operations): JsonResponse
    {
        $validated = $request->validate([
            'post_id' => ['required', 'integer'],
            'origin_share_id' => ['nullable', 'integer'],
        ]);
        $userId = (int) $request->session()->get('user_id');
        $operation = DB::transaction(function () use ($shares, $operations, $userId, $validated): array {
            $share = $shares->create($userId, $validated['post_id'], $validated['origin_share_id'] ?? null);

            return $operations->complete($userId, $share->only(['id', 'user_id', 'post_id', 'origin_share_id', 'created_at']));
        });

        return response()->json(['data' => $operation], 201);
    }
}
