<?php
declare(strict_types=1);
namespace App\Repository;

use App\Infrastructure\Neo4j\Neo4jClient;

final class DistributionTaskRepository
{
    public function __construct(private Neo4jClient $client) {}

    public function pending(int $size): array
    {
        return $this->client->run('MATCH (t:Task {published:false, done:false}) RETURN t.id AS id LIMIT $size', ['size' => $size]);
    }

    public function markPublished(array $task): void
    {
        $this->client->run('MATCH (t:Task {id:$id}) SET t.published=true', $task);
    }

    /** Cursor e entregas são confirmados juntos; reentrega não muda origem nem posição. */
    public function distribute(string $id, int $size): bool
    {
        $rows = $this->client->run('MATCH (t:Task {id:$id}), (p:Post {id:t.post_id}), (counter:Counter {id:"feed"})
            SET counter.value=counter.value+1
            WITH t,p,counter
            MATCH (recipientUser:User)-[:FOLLOWS]->(f:Follow)-[:TARGET]->(:User {id:t.actor})
            WHERE f.seguidor_id > t.cursor AND f.seguidor_id <> p.usuario_id AND f.position <= t.follow_cutoff AND t.done=false
            WITH t,p,counter,f ORDER BY f.seguidor_id LIMIT $size
            WITH t,p,counter,collect(f.seguidor_id) AS recipients
            UNWIND range(0,size(recipients)-1) AS i
            WITH t,p,counter,recipients,i,recipients[i] AS recipient
            MERGE (e:Entry {key:recipient+":"+p.id})
            ON CREATE SET e.usuario_id=recipient, e.post_id=p.id, e.seq=counter.value+i,
                e.origem=t.kind, e.autor_evento_id=t.actor, e.evento_id=t.id
            MERGE (o:Origin {key:recipient+":"+p.id+":"+t.id})
            ON CREATE SET o.usuario_id=recipient, o.post_id=p.id, o.evento_id=t.id,
                o.origem=t.kind, o.autor_evento_id=t.actor
            WITH t,counter,recipients,count(*) AS written
            SET counter.value=counter.value+size(recipients), t.cursor=last(recipients),
                t.done=size(recipients)<$size
            RETURN t.done AS done', ['id' => $id, 'size' => $size]);
        if (!$rows) {
            $this->client->run('MATCH (t:Task {id:$id}) SET t.done=true', ['id' => $id]);
            return true;
        }
        return $rows[0]['done'];
    }
}
