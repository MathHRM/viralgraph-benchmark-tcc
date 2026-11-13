<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;

class MetricsRepository
{
    public function __construct(
        private readonly FollowRepository $follows,
        private readonly PostRepository $posts,
        private readonly ShareRepository $shares,
        private readonly FeedItemRepository $feedItems,
    ) {}

    /** @return array<string, int> */
    public function logicalCreationCounts(): array
    {
        $users = User::query()->count();
        $follows = $this->follows->count();
        $posts = $this->posts->count();
        $shares = $this->shares->count();
        $feedItems = $this->feedItems->count();

        return [
            'usuarios' => $users,
            'feeds' => $users,
            'relacoes_seguir' => $follows,
            'posts_criados' => $posts,
            'compartilhamentos' => $shares,
            'entradas_feed' => $feedItems,
            'inserts_logicos' => $users + $users + $follows + $posts + $shares + $feedItems,
        ];
    }
}
