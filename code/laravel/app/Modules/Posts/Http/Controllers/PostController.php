<?php

namespace App\Modules\Posts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationService;
use App\Modules\Posts\Services\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    public function store(Request $request, PostService $posts, OperationService $operations): JsonResponse
    {
        $validated = $request->validate(['content' => ['required', 'string', 'max:500', 'not_regex:/^\\s*$/u']]);
        $userId = (int) $request->session()->get('user_id');
        $operation = DB::transaction(function () use ($posts, $operations, $userId, $validated): array {
            $post = $posts->create($userId, $validated['content']);

            return $operations->complete($userId, $post->only(['id', 'user_id', 'content', 'created_at']));
        });

        return response()->json(['data' => $operation], 201);
    }
}
