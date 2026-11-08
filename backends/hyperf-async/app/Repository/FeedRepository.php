<?php
declare(strict_types=1);
namespace App\Repository;

use App\Infrastructure\Neo4j\Neo4jClient;

final class FeedRepository
{
    public function __construct(private Neo4jClient $client) {}

    public function page(string $uid, ?array $cursor): array
    {
        return $this->client->run('MATCH (e:Entry {usuario_id:$uid}), (p:Post {id:e.post_id})
            WHERE $seq IS NULL OR e.seq < $seq OR (e.seq=$seq AND p.id < $last)
            RETURN properties(p) AS post, e.origem AS origem, e.autor_evento_id AS autor_evento_id,
                e.evento_id AS evento_id, e.seq AS seq
            ORDER BY seq DESC, post.id DESC LIMIT 51', [
            'uid' => $uid, 'seq' => $cursor[1] ?? null, 'last' => $cursor[2] ?? '',
        ]);
    }
}
