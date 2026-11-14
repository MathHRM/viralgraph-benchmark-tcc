<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Share;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ShareRepository
{
    public const TREE_DEPTH = 4;

    public const TREE_LIMIT = 1000;

    public function create(string $userId, string $postId, string $sourceUserId): Share
    {
        return Share::query()->create([
            'user_id' => $userId,
            'post_id' => $postId,
            'source_user_id' => $sourceUserId,
        ]);
    }

    public function exists(string $userId, string $postId): bool
    {
        return Share::query()
            ->where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    public function count(): int
    {
        return Share::query()->count();
    }

    /**
     * Árvore de propagação por CTE recursiva: parte dos compartilhamentos feitos a partir
     * do autor (nível 1) e desce por source_user_id até TREE_DEPTH níveis.
     * Unicidade (user_id, post_id) garante um único pai por nó, então não há ciclos.
     *
     * @return list<array{usuario_id: string, origem_usuario_id: string, nivel: int, compartilhamento_id: string, criado_em: string}>
     */
    public function propagationTree(string $postId, string $authorId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE tree (user_id, source_user_id, share_id, created_at, depth) AS (
                SELECT s.user_id, s.source_user_id, s.id, s.created_at, 1
                FROM shares s
                WHERE s.post_id = ? AND s.source_user_id = ?
                UNION ALL
                SELECT s.user_id, s.source_user_id, s.id, s.created_at, t.depth + 1
                FROM shares s
                JOIN tree t ON s.source_user_id = t.user_id
                WHERE s.post_id = ? AND t.depth < ?
            )
            SELECT user_id, source_user_id, share_id, created_at, depth
            FROM tree ORDER BY depth, user_id LIMIT ?',
            [$postId, $authorId, $postId, self::TREE_DEPTH, self::TREE_LIMIT + 1],
        );

        return array_map(static fn (object $row): array => [
            'usuario_id' => $row->user_id,
            'origem_usuario_id' => $row->source_user_id,
            'nivel' => (int) $row->depth,
            'compartilhamento_id' => $row->share_id,
            'criado_em' => Carbon::parse($row->created_at)->utc()->toRfc3339String(),
        ], $rows);
    }
}
