<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    /** @param array{name:string,nickname:string,birth_date:string} $attributes */
    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }

    public function find(string $id): ?User
    {
        return User::query()->find($id);
    }

    public function exists(string $id): bool
    {
        return User::query()->whereKey($id)->exists();
    }

    /** @return array{items: Collection<int, User>, next_cursor: ?string} */
    public function search(string $term, ?string $afterId): array
    {
        $pattern = self::literalContainsPattern($term);

        $query = User::query()
            ->where(function ($query) use ($pattern): void {
                $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("nickname LIKE ? ESCAPE '!'", [$pattern]);
            })
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

    private static function literalContainsPattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }
}
