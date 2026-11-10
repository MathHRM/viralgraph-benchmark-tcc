<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\FeedItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class FeedItemRepository
{
    /**
     * Insert one feed association. The unique (user_id, post_id) constraint
     * makes duplicate deliveries a no-op under concurrent fan-out.
     */
    public function insertIfAbsent(
        string $userId,
        string $postId,
        string $origin,
        string $eventAuthorId,
        string $eventId,
    ): bool {
        try {
            FeedItem::query()->create([
                'id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'post_id' => $postId,
                'origin' => $origin,
                'event_author_id' => $eventAuthorId,
                'event_id' => $eventId,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function contains(string $userId, string $postId): bool
    {
        return FeedItem::query()
            ->where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    public function count(): int
    {
        return FeedItem::query()->count();
    }
}
