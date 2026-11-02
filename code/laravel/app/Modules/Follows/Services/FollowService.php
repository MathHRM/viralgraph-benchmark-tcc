<?php

namespace App\Modules\Follows\Services;

use App\Modules\Follows\Models\Follow;
use App\Modules\Users\Models\User;

class FollowService
{
    /** @return array{status: int, body: array<string, mixed>} */
    public function create(int $followerId, int $followedId): array
    {
        $follower = User::find($followerId);
        $followed = User::find($followedId);

        if (! $follower || ! $followed) {
            return ['status' => 404, 'body' => ['message' => 'Referenced user not found.']];
        }
        if ($follower->is($followed)) {
            $message = 'A user cannot follow themself.';
            return ['status' => 422, 'body' => ['message' => $message, 'errors' => ['followed_id' => [$message]]]];
        }

        $follow = Follow::firstOrCreate(['follower_id' => $followerId, 'followed_id' => $followedId]);

        return [
            'status' => $follow->wasRecentlyCreated ? 201 : 200,
            'body' => ['data' => $follow->only(['id', 'follower_id', 'followed_id', 'created_at'])],
        ];
    }
}
