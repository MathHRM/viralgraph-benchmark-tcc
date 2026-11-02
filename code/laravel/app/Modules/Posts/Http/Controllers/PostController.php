<?php

namespace App\Modules\Posts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Posts\Services\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function store(Request $request, PostService $posts): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'content' => ['required', 'string', 'max:500', 'not_regex:/^\\s*$/u'],
        ]);
        $result = $posts->create($validated['user_id'], $validated['content']);

        return response()->json($result['body'], $result['status']);
    }
}
