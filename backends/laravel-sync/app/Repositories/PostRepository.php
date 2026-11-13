<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Post;
use Illuminate\Database\Eloquent\Collection;

class PostRepository
{
    public function create(string $userId, string $content): Post
    {
        return Post::query()->create([
            'user_id' => $userId,
            'content' => $content,
        ]);
    }

    public function find(string $id): ?Post
    {
        return Post::query()->find($id);
    }

    /** @return array{items: Collection<int, Post>, next_cursor: ?string} */
    public function search(string $term, ?string $afterId): array
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $query = Post::query()
            ->whereRaw("content LIKE ? ESCAPE '!'", [$pattern])
            ->orderBy('id');

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $items = $query->limit(51)->get();
        $hasMore = $items->count() > 50;
        $items = $items->take(50)->values();

        return [
            'items' => $items,
            'next_cursor' => $hasMore ? $items->last()->id : null,
        ];
    }

    public function count(): int
    {
        return Post::query()->count();
    }
}
