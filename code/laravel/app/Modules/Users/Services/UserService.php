<?php

namespace App\Modules\Users\Services;

use App\Modules\Shared\Exceptions\SocialDomainException;
use App\Modules\Users\Models\User;

class UserService
{
    public function create(string $username, string $password): User
    {
        $existing = User::whereRaw('LOWER(username) = ?', [strtolower($username)])->exists();

        if ($existing) {
            throw new SocialDomainException('username_taken', 'The username is already taken.', ['username' => ['The username is already taken.']]);
        }

        $user = User::create(['username' => $username, 'password' => $password]);

        return $user;
    }

    /** @return array{data: list<array{id: int, username: string}>, pagination: array{next_cursor: ?string}} */
    public function search(string $search, int $limit, ?string $cursor, int $authenticatedUserId): array
    {
        $query = User::query()->select(['id', 'username'])
            ->where('id', '<>', $authenticatedUserId)
            ->where('username', 'like', '%'.$search.'%')
            ->orderBy('id');
        if ($cursor !== null) {
            $afterId = $this->decodeCursor($cursor);
            if ($afterId !== null) {
                $query->where('id', '>', $afterId);
            }
        }
        $users = $query->limit($limit + 1)->get();
        $hasMore = $users->count() > $limit;
        $page = $users->take($limit);

        return [
            'data' => $page->map(fn (User $user): array => ['id' => $user->id, 'username' => $user->username])->values()->all(),
            'pagination' => ['next_cursor' => $hasMore ? base64_encode((string) $page->last()->id) : null],
        ];
    }

    private function decodeCursor(string $cursor): ?int
    {
        $decoded = base64_decode($cursor, true);

        return $decoded !== false && ctype_digit($decoded) ? (int) $decoded : null;
    }
}
