<?php
declare(strict_types=1);
namespace App\Repository;

use App\Infrastructure\Neo4j\Neo4jClient;

/**
 * Modelo da propagação:
 *   (u:User)-[:SHARED {key:"usuario:post"}]->(p:Post)              registro do compartilhamento
 *   (u:User)-[:RESHARED_FROM {key:"usuario:post", post_id}]->(o)    aresta da árvore (de quem recebeu)
 *   (f:User)-[:FEED]->(p)                                           entrega aos seguidores de u
 */
class ShareRepository
{
    public const TREE_DEPTH = 4;
    public const TREE_LIMIT = 500;

    public function __construct(private Neo4jClient $client) {}

    /** Estado necessário para validar o compartilhamento, em um único round-trip. */
    public function context(string $userId, string $postId, ?string $sourceId): array
    {
        return $this->client->run('OPTIONAL MATCH (u:User {id:$user})
            OPTIONAL MATCH (p:Post {id:$post})
            OPTIONAL MATCH (o:User {id:$source})
            RETURN u IS NOT NULL AS user_exists, p.usuario_id AS author_id,
                CASE WHEN u IS NULL OR p IS NULL THEN false ELSE exists { (u)-[:SHARED]->(p) } END AS already_shared,
                CASE WHEN o IS NULL OR p IS NULL THEN false ELSE exists { (o)-[:SHARED]->(p) } END AS source_shared', [
            'user' => $userId, 'post' => $postId, 'source' => $sourceId ?? '',
        ])[0];
    }

    /**
     * Persiste um lote de compartilhamentos em UMA transação (UNWIND): SHARED, aresta da árvore
     * e fan-out FEED para os seguidores de quem compartilhou. Um único commit por lote evita
     * transações concorrentes disputando o lock do mesmo :Post viral.
     *
     * Idempotente sob reentrega: SHARED.key/RESHARED_FROM.key/FEED.key são únicos; o fan-out só
     * roda quando o SHARED pertence a esta mensagem (sh.id = s.id). Destinatário que já tinha o
     * post mantém a primeira entrega; seq pode ganhar lacunas.
     *
     * @param list<array{id:string, usuario_id:string, post_id:string, origem_usuario_id:string, criado_em:string}> $shares
     * @return list<array{id:string, entregas:int}>
     */
    public function persistBatch(array $shares): array
    {
        return $this->client->run('UNWIND $shares AS s
            MATCH (u:User {id:s.usuario_id})
            MATCH (p:Post {id:s.post_id})
            MATCH (o:User {id:s.origem_usuario_id})
            MERGE (u)-[sh:SHARED {key: s.usuario_id + ":" + s.post_id}]->(p)
            ON CREATE SET sh.id=s.id, sh.usuario_id=s.usuario_id, sh.post_id=s.post_id,
                sh.origem_usuario_id=s.origem_usuario_id, sh.criado_em=s.criado_em
            WITH s, u, p, o, sh WHERE sh.id = s.id
            MERGE (u)-[r:RESHARED_FROM {key: s.usuario_id + ":" + s.post_id}]->(o)
            ON CREATE SET r.post_id=s.post_id, r.share_id=s.id, r.criado_em=s.criado_em
            WITH s, u, p
            CALL (s, u, p) {
                MATCH (f:User)-[:FOLLOWS]->(u)
                WHERE f.id <> p.usuario_id
                SET f.feed_seq = coalesce(f.feed_seq, 0) + 1
                MERGE (f)-[e:FEED {key: f.id + ":" + p.id}]->(p)
                ON CREATE SET e.seq=f.feed_seq, e.origem="share",
                    e.autor_evento_id=s.usuario_id, e.evento_id=s.id
                RETURN count(f) AS entregas
            }
            RETURN s.id AS id, entregas', ['shares' => array_values($shares)]);
    }

    /**
     * Árvore de propagação por caminho variável: ancorada no :Post (índice único) e no autor,
     * percorre até TREE_DEPTH saltos de RESHARED_FROM restritos ao mesmo post. O filtro como mapa
     * de propriedades na relação ({post_id}) é avaliado DENTRO da expansão (poda); um WHERE all(...)
     * posterior expandia as arestas de todos os posts do autor (~20x mais db hits no PROFILE).
     * Cada usuário tem no máximo uma aresta por post (RESHARED_FROM.key), então há um único
     * caminho por nó. ORDER BY/LIMIT antes de montar os mapas: a variante com COLLECT {} e RETURN
     * de mapa custava ~10x mais tempo no PROFILE do post mais compartilhado do dataset.
     * O LIMIT (TREE_LIMIT + 1) limita o payload de posts super-virais e sinaliza truncamento.
     *
     * @return array{author_id:string, edges:list<array{usuario_id:string, origem_usuario_id:string, nivel:int, compartilhamento_id:string, criado_em:string}>}|null
     */
    public function tree(string $postId): ?array
    {
        $rows = $this->client->run('MATCH (p:Post {id:$post})
            MATCH (a:User {id:p.usuario_id})
            CALL (a) {
                MATCH path = (u)-[:RESHARED_FROM*1..'.self::TREE_DEPTH.' {post_id: $post}]->(a)
                WITH u, relationships(path) AS rels
                WITH u, head(rels) AS edge, size(rels) AS nivel
                ORDER BY nivel, u.id LIMIT $limit
                RETURN collect({usuario_id: u.id, origem_usuario_id: endNode(edge).id, nivel: nivel,
                    compartilhamento_id: edge.share_id, criado_em: edge.criado_em}) AS edges
            }
            RETURN a.id AS author_id, edges', ['post' => $postId, 'limit' => self::TREE_LIMIT + 1]);
        return $rows ? $rows[0] : null;
    }
}
