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
        $validated = $request->validate(['followed_id' => ['required', 'integer', 'min:1']]);
        $follow = $follows->create((int) $request->session()->get('user_id'), $validated['followed_id']);

        return response()->json(['data' => $follow->only(['id', 'follower_id', 'followed_id', 'created_at'])], 201);
    }

    public function destroy(Request $request, int $user_id, FollowService $follows): JsonResponse
    {
        $follows->delete((int) $request->session()->get('user_id'), $user_id);

        return response()->json(['data' => ['status' => 'unfollowed']]);
    }
}
