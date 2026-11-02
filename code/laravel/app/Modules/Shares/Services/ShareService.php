<?php

namespace App\Modules\Shares\Services;

use App\Modules\Posts\Models\Post;
use App\Modules\Shared\Exceptions\SocialDomainException;
use App\Modules\Shares\Models\Share;
use App\Modules\Users\Models\User;

class ShareService
{
    public function create(int $userId, int $postId, ?int $originShareId): Share
    {
        $user = User::find($userId);
        $post = Post::find($postId);
        if (! $user || ! $post) {
            throw new SocialDomainException('resource_not_found', 'Referenced user or post not found.');
        }
        if ($user->id === $post->user_id) {
            return $this->invalid('post_id', 'A user cannot share their own post.');
        }
        if ($originShareId) {
            $origin = Share::find($originShareId);
            if (! $origin) {
                throw new SocialDomainException('share_not_found', 'Origin share not found.');
            }
            if ($origin->post_id !== $post->id) {
                return $this->invalid('origin_share_id', 'The origin share must refer to the same original post.');
            }
        }

        $share = Share::firstOrCreate(
            ['user_id' => $userId, 'post_id' => $postId],
            ['origin_share_id' => $originShareId],
        );

        return $share;
    }

    private function invalid(string $field, string $message): never
    {
        throw new SocialDomainException('validation_failed', $message, [$field => [$message]]);
    }
}
