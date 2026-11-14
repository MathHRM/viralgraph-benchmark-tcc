<?php
declare(strict_types=1);
namespace App\Infrastructure\Neo4j;

/**
 * Modelo nativo de grafo (ver docs/modelo-grafo.md):
 *   (:User)-[:FOLLOWS]->(:User)      relação de seguir
 *   (:User)-[:AUTHORED]->(:Post)     autoria
 *   (:User)-[:SHARED]->(:Post)       compartilhamento
 *   (:User)-[:RESHARED_FROM]->(:User) aresta da árvore de propagação (post_id)
 *   (:User)-[:FEED]->(:Post)         entrada de feed (primeira entrega)
 *   (:User)-[:VIA]->(:Post)          cada caminho de entrega (auditoria)
 *   (:Task)                          outbox de distribuição
 */
final class SchemaInitializer
{
    /** Unicidade em nós. */
    private const NODE_UNIQUE = [
        'User' => ['id', 'apelido'],
        'Post' => ['id'],
        'Task' => ['id'],
    ];

    /** Unicidade em relações (propriedade única; disponível na Community 5.26). */
    private const RELATIONSHIP_UNIQUE = [
        'FOLLOWS' => 'key', // seguidor:seguido
        'SHARED' => 'key',  // usuario:post
        'RESHARED_FROM' => 'key', // usuario:post (um pai por nó da árvore)
        'FEED' => 'key',    // destinatario:post
        'VIA' => 'key',     // destinatario:post:evento
    ];

    /** Índices das consultas quentes: outbox pendente e seguidores por alvo, em ordem de cursor. */
    private const INDEXES = [
        'task_pending' => 'FOR (n:Task) ON (n.published, n.done)',
        'follows_target_follower' => 'FOR ()-[f:FOLLOWS]-() ON (f.seguido_id, f.seguidor_id)',
        'reshared_post_id' => 'FOR ()-[r:RESHARED_FROM]-() ON (r.post_id)',
    ];

    public function __construct(private Neo4jClient $client) {}

    public function initialize(): void
    {
        foreach (self::NODE_UNIQUE as $label => $fields) {
            foreach ($fields as $field) {
                $this->client->run("CREATE CONSTRAINT IF NOT EXISTS FOR (n:$label) REQUIRE n.$field IS UNIQUE");
            }
        }
        foreach (self::RELATIONSHIP_UNIQUE as $type => $field) {
            $this->client->run("CREATE CONSTRAINT IF NOT EXISTS FOR ()-[r:$type]-() REQUIRE r.$field IS UNIQUE");
        }
        foreach (self::INDEXES as $name => $target) {
            $this->client->run("CREATE INDEX $name IF NOT EXISTS $target");
        }
        $this->client->run('CALL db.awaitIndexes(300)');
    }
}
