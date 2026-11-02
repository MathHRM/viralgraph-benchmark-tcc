<?php

namespace App\Modules\Security\Services;

use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Hash;

class SessionService
{
    /** @return array{id: int, username: string}|null */
    public function login(string $username, string $password): ?array
    {
        $user = User::whereRaw('LOWER(username) = ?', [strtolower($username)])->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $this->publicIdentity($user);
    }

    /** @return array{id: int, username: string}|null */
    public function identity(?int $userId): ?array
    {
        if (! $userId) {
            return null;
        }

        $user = User::find($userId);

        return $user ? $this->publicIdentity($user) : null;
    }

    /** @return array{id: int, username: string} */
    private function publicIdentity(User $user): array
    {
        return ['id' => $user->id, 'username' => $user->username];
    }
}
