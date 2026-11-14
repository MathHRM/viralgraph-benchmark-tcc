<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\FeedItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeedItemRepository
{
    /** Destinatários por comando: limita o tamanho dos IN/INSERT multi-linha. */
    public const FAN_OUT_CHUNK = 500;

    /**
     * Fan-out síncrono em lotes, dentro da transação do chamador. Por lote:
     * 1. UPDATE único reserva a próxima seq de cada destinatário (trava as linhas em ordem de PK,
     *    o que reduz deadlocks entre fan-outs concorrentes que compartilham seguidores);
     * 2. SELECT lê as seqs reservadas;
     * 3. INSERT IGNORE multi-linha: UNIQUE (user_id, post_id) torna reentregas um no-op,
     *    preservando procedência e posição da primeira entrega. Seq pode ficar com lacunas.
     *
     * @param  list<string>  $recipientIds  ordenados por id
     * @return int entradas efetivamente criadas
     */
    public function fanOut(
        array $recipientIds,
        string $postId,
        string $origin,
        string $eventAuthorId,
        string $eventId,
    ): int {
        $inserted = 0;
        foreach (array_chunk($recipientIds, self::FAN_OUT_CHUNK) as $chunk) {
            DB::table('users')->whereIn('id', $chunk)->increment('feed_seq');
            $seqs = DB::table('users')->whereIn('id', $chunk)->pluck('feed_seq', 'id');
            $rows = [];
            foreach ($chunk as $userId) {
                if (! isset($seqs[$userId])) {
                    continue;
                }
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'user_id' => $userId,
                    'post_id' => $postId,
                    'origin' => $origin,
                    'event_author_id' => $eventAuthorId,
                    'event_id' => $eventId,
                    'seq' => (int) $seqs[$userId],
                ];
            }
            if ($rows !== []) {
                $inserted += DB::table('feed_items')->insertOrIgnore($rows);
            }
        }

        return $inserted;
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
