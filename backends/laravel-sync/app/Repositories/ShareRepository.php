<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Share;

class ShareRepository
{
    public function create(string $userId, string $postId): Share
    {
        return Share::query()->create([
            'user_id' => $userId,
            'post_id' => $postId,
        ]);
    }

    public function exists(string $userId, string $postId): bool
    {
        return Share::query()
            ->where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    public function count(): int
    {
        return Share::query()->count();
    }
}
