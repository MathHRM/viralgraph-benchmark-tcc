<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\FeedItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeedItemRepository
{
    /**
     * Insert one feed association. The unique (user_id, post_id) constraint
     * makes duplicate deliveries a no-op under concurrent fan-out.
     */
    public function insertIfAbsent(
        string $userId,
        string $postId,
        string $origin,
        string $eventAuthorId,
        string $eventId,
    ): bool {
        try {
            $current = DB::table('users')->where('id', $userId)->lockForUpdate()->value('feed_seq');
            if ($current === null) {
                return false;
            }
            $seq = (int) $current + 1;
            DB::table('users')->where('id', $userId)->update(['feed_seq' => $seq]);

            FeedItem::query()->create([
                'id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'post_id' => $postId,
                'origin' => $origin,
                'event_author_id' => $eventAuthorId,
                'event_id' => $eventId,
                'seq' => $seq,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function contains(string $userId, string $postId): bool
    {
        return FeedItem::query()
            ->where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    public function count(): int
    {
        return FeedItem::query()->count();
    }

    /** @param array{1: int, 2: string}|null $cursor */
    public function page(string $userId, ?array $cursor): array
    {
        $query = DB::table('feed_items as f')
            ->join('posts as p', 'p.id', '=', 'f.post_id')
            ->where('f.user_id', $userId)
            ->select([
                'f.seq', 'f.origin', 'f.event_author_id', 'f.event_id',
                'p.id as post_id', 'p.user_id as post_user_id', 'p.content as post_content',
                'p.created_at as post_created_at',
            ]);

        if ($cursor !== null) {
            $query->where(function (Builder $query) use ($cursor): void {
                $query->where('f.seq', '<', $cursor[1])
                    ->orWhere(function (Builder $query) use ($cursor): void {
                        $query->where('f.seq', '=', $cursor[1])
                            ->where('p.id', '<', $cursor[2]);
                    });
            });
        }

        return $query->orderByDesc('f.seq')->orderByDesc('p.id')->limit(51)->get()
            ->map(fn ($row): array => [
                'seq' => (int) $row->seq,
                'post' => [
                    'id' => $row->post_id,
                    'usuario_id' => $row->post_user_id,
                    'conteudo' => $row->post_content,
                    'criado_em' => Carbon::parse($row->post_created_at)->utc()->toRfc3339String(),
                ],
                'origem' => $row->origin,
                'autor_evento_id' => $row->event_author_id,
                'evento_id' => $row->event_id,
            ])->all();
    }
}
