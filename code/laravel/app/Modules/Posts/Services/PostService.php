<?php

namespace App\Modules\Posts\Services;

use App\Modules\Posts\Models\Post;

class PostService
{
    public function create(int $userId, string $content): Post
    {
        return Post::create(['user_id' => $userId, 'content' => $content]);
    }
}
