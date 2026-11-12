<?php
declare(strict_types=1);
namespace App\Repository;

use App\Exception\ApiError;
use App\Infrastructure\Neo4j\Neo4jClient;

final class FeedRepository
{
    public function __construct(private Neo4jClient $client) {}

    /**
     * Percorre só as relações FEED do usuário (index-free adjacency): custo proporcional
     * ao feed dele, não ao total de entradas do banco. seq é única por usuário.
     */
    public function page(string $uid, ?array $cursor): array
    {
        $rows = $this->client->run('MATCH (u:User {id:$uid})
            RETURN u.id AS user_id, COLLECT {
                MATCH (u)-[e:FEED]->(p:Post)
                WHERE $seq IS NULL OR e.seq < $seq OR (e.seq=$seq AND p.id < $last)
                RETURN {post: properties(p), origem: e.origem, autor_evento_id: e.autor_evento_id,
                    evento_id: e.evento_id, seq: e.seq}
                ORDER BY e.seq DESC, p.id DESC LIMIT 51
            } AS entries', [
            'uid' => $uid, 'seq' => $cursor[1] ?? null, 'last' => $cursor[2] ?? '',
        ]);
        if (!$rows) {
            throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        }
        return $rows[0]['entries'];
    }
}
