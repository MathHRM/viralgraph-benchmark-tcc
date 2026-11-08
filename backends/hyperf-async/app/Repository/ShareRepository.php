<?php
declare(strict_types=1);
namespace App\Repository;

use App\Infrastructure\Neo4j\Neo4jClient;

final class ShareRepository
{
    public function __construct(private Neo4jClient $client) {}

    public function create(array $result): array
    {
        return $this->client->run('MATCH (e:Entry {key:$key}), (u:User {id:$actor}), (p:Post {id:$post}), (clock:Counter {id:"social"})
            SET clock.value=clock.value+1
            CREATE (u)-[:SHARED]->(s:Share $v)-[:ORIGINAL]->(p)
            CREATE (t:Task {id:$id, post_id:$post, actor:$actor, kind:"compartilhamento",
                cursor:"", published:false, done:false, follow_cutoff:clock.value})
            RETURN s.id AS id', [
            'key' => $result['usuario_id'].':'.$result['post_id'],
            'v' => $result + ['key' => $result['usuario_id'].':'.$result['post_id']],
            'id' => $result['id'], 'post' => $result['post_id'], 'actor' => $result['usuario_id'],
        ]);
    }
}
