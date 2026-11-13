<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Follow;
use Illuminate\Support\Collection;

class FollowRepository
{
    public function create(string $followerId, string $followedId): Follow
    {
        return Follow::query()->create([
            'follower_id' => $followerId,
            'followed_id' => $followedId,
        ]);
    }

    /** @return Collection<int, string> */
    public function followerIdsOf(string $userId): Collection
    {
        return Follow::query()
            ->where('followed_id', $userId)
            ->orderBy('follower_id')
            ->pluck('follower_id');
    }

    public function exists(string $followerId, string $followedId): bool
    {
        return Follow::query()
            ->where('follower_id', $followerId)
            ->where('followed_id', $followedId)
            ->exists();
    }

    public function count(): int
    {
        return Follow::query()->count();
    }
}
