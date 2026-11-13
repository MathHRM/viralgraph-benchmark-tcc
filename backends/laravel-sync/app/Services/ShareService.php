<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Post;
use App\Models\Share;
use App\Models\User;
use App\Repositories\FeedItemRepository;
use App\Repositories\FollowRepository;
use App\Repositories\PostRepository;
use App\Repositories\ShareRepository;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShareService
{
    public function __construct(
        private readonly ShareRepository $shares,
        private readonly UserRepository $users,
        private readonly PostRepository $posts,
        private readonly FollowRepository $follows,
        private readonly FeedItemRepository $feedItems,
    ) {}

    public function create(string $userId, string $postId): Share
    {
        $user = $this->users->find($userId);
        $post = $this->posts->find($postId);

        if (! $user) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        if (! $post) {
            throw (new ModelNotFoundException)->setModel(Post::class, [$postId]);
        }

        if ($post->user_id === $userId) {
            throw ValidationException::withMessages([
                'post_id' => ['O autor não pode compartilhar o próprio post.'],
            ]);
        }

        if (! $this->feedItems->contains($userId, $postId)) {
            throw ValidationException::withMessages([
                'post_id' => ['O post precisa estar no feed do usuário para ser compartilhado.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($userId, $postId, $post): Share {
                $share = $this->shares->create($userId, $postId);

                foreach ($this->follows->followerIdsOf($userId) as $followerId) {
                    if ($followerId === $post->user_id) {
                        continue;
                    }

                    $this->feedItems->insertIfAbsent(
                        $followerId,
                        $postId,
                        'share',
                        $userId,
                        $share->id,
                    );
                }

                return $share;
            }, 3);
        } catch (QueryException $exception) {
            if (UserService::isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'post_id' => ['Este post já foi compartilhado por este usuário.'],
                ]);
            }

            throw $exception;
        }
    }
}
