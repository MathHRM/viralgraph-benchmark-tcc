<?php
declare(strict_types=1);
namespace App\Infrastructure\Neo4j;

final class SchemaInitializer
{
    /** Índices das consultas quentes: feed por usuário, outbox pendente e seguidores por alvo com cursor. */
    private const INDEXES = [
        'entry_user_seq' => 'Entry) ON (n.usuario_id, n.seq',
        'task_pending' => 'Task) ON (n.published, n.done',
        'follow_target_follower' => 'Follow) ON (n.seguido_id, n.seguidor_id',
    ];

    public function __construct(private Neo4jClient $client) {}

    public function initialize(): void
    {
        foreach (['User' => ['id', 'apelido'], 'Follow' => ['id', 'key'], 'Post' => ['id'], 'Share' => ['id', 'key'], 'Entry' => ['key'], 'Origin' => ['key'], 'Task' => ['id'], 'Counter' => ['id']] as $label => $fields) {
            foreach ($fields as $field) {
                $this->client->run("CREATE CONSTRAINT IF NOT EXISTS FOR (n:$label) REQUIRE n.$field IS UNIQUE");
            }
        }
        foreach (self::INDEXES as $name => $target) {
            $this->client->run("CREATE INDEX $name IF NOT EXISTS FOR (n:$target)");
        }
        $this->client->run('CALL db.awaitIndexes(300)');
        $this->client->run("UNWIND ['feed','social'] AS id MERGE (c:Counter {id:id}) ON CREATE SET c.value=0");
    }
}
