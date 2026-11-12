<?php
declare(strict_types=1);
namespace App\Repository;

use App\Infrastructure\Neo4j\Neo4jClient;

final class ShareRepository
{
    public function __construct(private Neo4jClient $client) {}

    /**
     * Exige o post no feed do usuário: o padrão (u)-[:FEED]->(p) já prova a presença.
     * SHARED.key barra compartilhamento repetido; a tarefa entra na mesma transação (outbox).
     */
    public function create(array $result): array
    {
        return $this->client->run('MATCH (u:User {id:$actor})-[:FEED]->(p:Post {id:$post})
            CREATE (u)-[s:SHARED]->(p)
            SET s = $v
            CREATE (t:Task {id:$id, post_id:$post, actor:$actor, kind:"compartilhamento",
                cursor:"", published:false, done:false, follow_cutoff:timestamp()})
            RETURN s.id AS id', [
            'v' => $result + ['key' => $result['usuario_id'].':'.$result['post_id']],
            'id' => $result['id'], 'post' => $result['post_id'], 'actor' => $result['usuario_id'],
        ]);
    }
}
