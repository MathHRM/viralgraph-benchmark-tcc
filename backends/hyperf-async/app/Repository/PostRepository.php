<?php
declare(strict_types=1);
namespace App\Repository;

use App\Exception\ApiError;
use App\Infrastructure\Neo4j\Neo4jClient;

final class PostRepository
{
    public function __construct(private Neo4jClient $client) {}

    public function get(string $id): array
    {
        $rows = $this->client->run("MATCH (n:Post {id:\$id}) RETURN properties(n) AS n", ['id' => $id]);
        if (!$rows) {
            throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        }
        return $rows[0]['n'];
    }

    public function search(string $term, ?array $cursor): array
    {
        return $this->client->run('MATCH (n:Post) WHERE n.conteudo CONTAINS $term AND n.id > $last RETURN properties(n) AS item ORDER BY n.id LIMIT 51', [
            'term' => $term, 'last' => $cursor[2] ?? '',
        ]);
    }

    public function persistPosts(array $posts): void
    {
        // follow_cutoff = relógio do Neo4j neste lote: seguidores posteriores ficam fora do fan-out.
        $this->client->run('UNWIND $posts AS p
            MATCH (u:User {id:p.usuario_id})
            MERGE (n:Post {id:p.id})
            ON CREATE SET n.usuario_id=p.usuario_id, n.conteudo=p.conteudo, n.criado_em=p.criado_em
            MERGE (u)-[:AUTHORED]->(n)
            MERGE (t:Task {id:p.id})
            ON CREATE SET t.post_id=p.id, t.actor=p.usuario_id, t.kind="publicacao",
                t.cursor="", t.published=false, t.done=false, t.follow_cutoff=timestamp()', ['posts' => $posts]);
    }
}
