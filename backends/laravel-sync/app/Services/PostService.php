<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use App\Repositories\FeedItemRepository;
use App\Repositories\FollowRepository;
use App\Repositories\PostRepository;
use App\Repositories\UserRepository;
use App\Support\CursorCodec;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class PostService
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly UserRepository $users,
        private readonly FollowRepository $follows,
        private readonly FeedItemRepository $feedItems,
    ) {}

    public function create(string $userId, string $content): Post
    {
        if (! $this->users->exists($userId)) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        return DB::transaction(function () use ($userId, $content): Post {
            $post = $this->posts->create($userId, $content);

            $recipients = $this->follows->followerIdsOf($userId)
                ->reject(fn (string $id): bool => $id === $userId)->values()->all();
            $this->feedItems->fanOut($recipients, $post->id, 'post', $userId, $post->id);

            return $post;
        }, 3);
    }

    public function show(string $id): Post
    {
        return $this->posts->find($id) ?? throw (new ModelNotFoundException)
            ->setModel(Post::class, [$id]);
    }

    /** @return array{data: list<array<string, mixed>>, proximo_cursor: ?string} */
    public function search(string $term, ?string $cursor): array
    {
        $result = $this->posts->search($term, CursorCodec::decode($cursor));

        return [
            'data' => $result['items']->map(fn (Post $post): array => self::toApi($post))->all(),
            'proximo_cursor' => $result['next_cursor'] === null
                ? null
                : CursorCodec::encode($result['next_cursor']),
        ];
    }

    /** @return array{id: string, usuario_id: string, conteudo: string, criado_em: string} */
    public static function toApi(Post $post): array
    {
        return [
            'id' => $post->id,
            'usuario_id' => $post->user_id,
            'conteudo' => $post->content,
            'criado_em' => $post->created_at->utc()->toRfc3339String(),
        ];
    }
}
