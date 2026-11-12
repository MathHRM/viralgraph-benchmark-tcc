<?php
declare(strict_types=1);
namespace App\Repository;

use App\Exception\ApiError;
use App\Infrastructure\Neo4j\Neo4jClient;

final class FeedRepository
{
    public function __construct(private Neo4jClient $client) {}

    public function page(string $uid, ?array $cursor): array
    {
        $rows = $this->client->run('MATCH (u:User {id:$uid})
            RETURN u.id AS user_id, COLLECT {
                MATCH (e:Entry {usuario_id:$uid}) USING INDEX e:Entry(usuario_id, seq)
                WHERE e.seq IS NOT NULL AND ($seq IS NULL OR e.seq < $seq OR (e.seq=$seq AND e.post_id < $last))
                WITH e ORDER BY e.seq DESC, e.post_id DESC LIMIT 51
                MATCH (p:Post {id:e.post_id})
                RETURN {post: properties(p), origem: e.origem, autor_evento_id: e.autor_evento_id,
                    evento_id: e.evento_id, seq: e.seq}
                ORDER BY e.seq DESC, e.post_id DESC
            } AS entries', [
            'uid' => $uid, 'seq' => $cursor[1] ?? null, 'last' => $cursor[2] ?? '',
        ]);
        if (!$rows) {
            throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        }
        return $rows[0]['entries'];
    }
}
