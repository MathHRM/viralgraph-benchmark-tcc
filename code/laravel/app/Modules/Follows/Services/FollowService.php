<?php

namespace App\Modules\Follows\Services;

use App\Modules\Follows\Models\Follow;
use App\Modules\Shared\Exceptions\SocialDomainException;
use App\Modules\Users\Models\User;

class FollowService
{
    public function create(int $followerId, int $followedId): Follow
    {
        $followed = User::find($followedId);

        if (! $followed) {
            throw new SocialDomainException('user_not_found', 'User not found.');
        }
        if ($followerId === $followedId) {
            $message = 'A user cannot follow themself.';

            throw new SocialDomainException('validation_failed', $message, ['followed_id' => [$message]]);
        }

        $follow = Follow::firstOrCreate(['follower_id' => $followerId, 'followed_id' => $followedId]);

        return $follow;
    }

    public function delete(int $followerId, int $followedId): void
    {
        Follow::where('follower_id', $followerId)->where('followed_id', $followedId)->delete();
    }
}
