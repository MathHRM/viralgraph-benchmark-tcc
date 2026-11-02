<?php

namespace App\Modules\Users\Services;

use App\Modules\Users\Models\User;

class UserService
{
    /** @return array{status: int, body: array<string, mixed>} */
    public function create(string $username): array
    {
        $user = User::firstOrCreate(['username' => $username]);

        if (! $user->wasRecentlyCreated) {
            return ['status' => 409, 'body' => [
                'message' => 'The username has already been taken.',
                'errors' => ['username' => ['The username has already been taken.']],
            ]];
        }

        return ['status' => 201, 'body' => ['data' => $user->only(['id', 'username', 'created_at'])]];
    }
}
