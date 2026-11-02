<?php

namespace App\Modules\Shares\Services;

use App\Modules\Posts\Models\Post;
use App\Modules\Shares\Models\Share;
use App\Modules\Users\Models\User;

class ShareService
{
    /** @return array{status: int, body: array<string, mixed>} */
    public function create(int $userId, int $postId, ?int $originShareId): array
    {
        $user = User::find($userId);
        $post = Post::find($postId);
        if (! $user || ! $post) {
            return ['status' => 404, 'body' => ['message' => 'Referenced user or post not found.']];
        }
        if ($user->id === $post->user_id) {
            return $this->invalid('post_id', 'A user cannot share their own post.');
        }
        if ($originShareId) {
            $origin = Share::find($originShareId);
            if (! $origin) {
                return ['status' => 404, 'body' => ['message' => 'Origin share not found.']];
            }
            if ($origin->post_id !== $post->id) {
                return $this->invalid('origin_share_id', 'The origin share must refer to the same original post.');
            }
        }

        $share = Share::firstOrCreate(
            ['user_id' => $userId, 'post_id' => $postId],
            ['origin_share_id' => $originShareId],
        );

        return [
            'status' => $share->wasRecentlyCreated ? 201 : 200,
            'body' => ['data' => $share->only(['id', 'user_id', 'post_id', 'origin_share_id', 'created_at'])],
        ];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function invalid(string $field, string $message): array
    {
        return ['status' => 422, 'body' => ['message' => $message, 'errors' => [$field => [$message]]]];
    }
}
