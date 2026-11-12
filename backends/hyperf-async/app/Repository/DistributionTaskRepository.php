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

    /** @param list<string> $ids */
    public function markPublished(array $ids): void
    {
        $this->client->run('UNWIND $ids AS id MATCH (t:Task {id:id}) SET t.published=true', ['ids' => $ids]);
    }

    /**
     * Uma fatia do fan-out: até $size seguidores do ator, a partir do cursor, numa transação.
     *
     * - Seguidores: seek no índice de relação follows_target_follower (seguido_id, seguidor_id).
     *   ORDER BY nas duas chaves deixa o índice entregar já ordenado; LIMIT corta cedo, então
     *   cada fatia lê ~$size relações mesmo quando o ator é um hub.
     * - seq: contador por destinatário (u.feed_seq). SET trava só o nó do destinatário,
     *   sem lock global; pode deixar lacunas quando a entrada já existia.
     * - FEED.key único: primeira entrega fixa origem e seq; reentrega/caminho novo não duplica.
     * - VIA: um registro por caminho (evento), só para auditoria.
     * Cursor e entregas são confirmados juntos.
     */
    public function distribute(string $id, int $size): bool
    {
        $rows = $this->client->run('MATCH (t:Task {id:$id}) WHERE t.done=false
            MATCH (p:Post {id:t.post_id})
            CALL (t,p) {
                MATCH (r:User)-[f:FOLLOWS]->() USING INDEX f:FOLLOWS(seguido_id, seguidor_id)
                WHERE f.seguido_id = t.actor AND f.seguidor_id > t.cursor
                    AND f.seguidor_id <> p.usuario_id AND f.at <= t.follow_cutoff
                RETURN r ORDER BY f.seguido_id, f.seguidor_id LIMIT $size
            }
            SET r.feed_seq = coalesce(r.feed_seq, 0) + 1
            MERGE (r)-[e:FEED {key: r.id + ":" + p.id}]->(p)
            ON CREATE SET e.seq=r.feed_seq, e.origem=t.kind, e.autor_evento_id=t.actor, e.evento_id=t.id
            MERGE (r)-[v:VIA {key: r.id + ":" + p.id + ":" + t.id}]->(p)
            ON CREATE SET v.evento_id=t.id, v.origem=t.kind, v.autor_evento_id=t.actor
            WITH t, collect(r.id) AS recipients
            SET t.cursor=last(recipients), t.done=size(recipients)<$size
            RETURN t.done AS done', ['id' => $id, 'size' => $size]);
        if (!$rows) {
            $this->client->run('MATCH (t:Task {id:$id}) SET t.done=true', ['id' => $id]);
            return true;
        }
        return $rows[0]['done'];
    }
}
