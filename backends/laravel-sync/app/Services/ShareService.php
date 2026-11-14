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

    /**
     * Compartilhamento 100% síncrono: validação, registro na árvore de propagação e fan-out
     * para os seguidores de quem compartilhou acontecem na mesma requisição e transação.
     */
    public function create(string $postId, string $userId, ?string $sourceUserId): Share
    {
        $user = $this->users->find($userId);
        if (! $user) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        $post = $this->posts->find($postId);
        if (! $post) {
            throw (new ModelNotFoundException)->setModel(Post::class, [$postId]);
        }

        if ($post->user_id === $userId) {
            throw ValidationException::withMessages([
                'usuario_id' => ['O autor não pode compartilhar o próprio post.'],
            ]);
        }

        $sourceUserId ??= $post->user_id;
        if ($sourceUserId === $userId) {
            throw ValidationException::withMessages([
                'origem_usuario_id' => ['A origem não pode ser o próprio usuário.'],
            ]);
        }

        // Origem válida: o autor (nível 1) ou alguém que já compartilhou este post.
        if ($sourceUserId !== $post->user_id && ! $this->shares->exists($sourceUserId, $postId)) {
            throw ValidationException::withMessages([
                'origem_usuario_id' => ['A origem precisa ser o autor ou um compartilhador do post.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($userId, $postId, $post, $sourceUserId): Share {
                $share = $this->shares->create($userId, $postId, $sourceUserId);

                $recipients = $this->follows->followerIdsOf($userId)
                    ->reject(fn (string $id): bool => $id === $post->user_id)->values()->all();
                $this->feedItems->fanOut($recipients, $postId, 'share', $userId, $share->id);

                return $share;
            }, 3);
        } catch (QueryException $exception) {
            if (UserService::isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'usuario_id' => ['Este post já foi compartilhado por este usuário.'],
                ]);
            }

            throw $exception;
        }
    }

    /** @return array{post_id: string, autor_id: string, profundidade_maxima: int, truncado: bool, data: list<array<string, mixed>>} */
    public function propagationTree(string $postId): array
    {
        $post = $this->posts->find($postId) ?? throw (new ModelNotFoundException)
            ->setModel(Post::class, [$postId]);
        $edges = $this->shares->propagationTree($postId, $post->user_id);
        $truncated = count($edges) > ShareRepository::TREE_LIMIT;

        return [
            'post_id' => $post->id,
            'autor_id' => $post->user_id,
            'profundidade_maxima' => ShareRepository::TREE_DEPTH,
            'truncado' => $truncated,
            'data' => array_slice($edges, 0, ShareRepository::TREE_LIMIT),
        ];
    }
}
