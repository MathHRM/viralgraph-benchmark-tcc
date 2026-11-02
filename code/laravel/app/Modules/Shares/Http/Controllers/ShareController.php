<?php

namespace App\Modules\Shares\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shares\Services\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShareController extends Controller
{
    public function store(Request $request, ShareService $shares): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'post_id' => ['required', 'integer'],
            'origin_share_id' => ['nullable', 'integer'],
        ]);
        $result = $shares->create(
            $validated['user_id'],
            $validated['post_id'],
            $validated['origin_share_id'] ?? null,
        );

        return response()->json($result['body'], $result['status']);
    }
}
