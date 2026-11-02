<?php

namespace App\Modules\Follows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Follows\Services\FollowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    public function store(Request $request, FollowService $follows): JsonResponse
    {
        $validated = $request->validate([
            'follower_id' => ['required', 'integer'],
            'followed_id' => ['required', 'integer'],
        ]);
        $result = $follows->create($validated['follower_id'], $validated['followed_id']);

        return response()->json($result['body'], $result['status']);
    }
}
