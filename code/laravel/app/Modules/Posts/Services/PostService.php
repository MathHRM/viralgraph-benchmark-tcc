<?php

namespace App\Modules\Posts\Services;

use App\Modules\Posts\Models\Post;
use App\Modules\Users\Models\User;

class PostService
{
    /** @return array{status: int, body: array<string, mixed>} */
    public function create(int $userId, string $content): array
    {
        if (! User::whereKey($userId)->exists()) {
            return ['status' => 404, 'body' => ['message' => 'Referenced user not found.']];
        }
        $post = Post::create(['user_id' => $userId, 'content' => $content]);

        return ['status' => 201, 'body' => ['data' => $post->only(['id', 'user_id', 'content', 'created_at'])]];
    }
}
