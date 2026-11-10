<?php
declare(strict_types=1);
namespace App\Infrastructure\Neo4j;

final class SchemaInitializer
{
    public function __construct(private Neo4jClient $client) {}

    public function initialize(): void
    {
        foreach (['User' => ['id', 'apelido'], 'Follow' => ['id', 'key'], 'Post' => ['id'], 'Share' => ['id', 'key'], 'Entry' => ['key'], 'Origin' => ['key'], 'Task' => ['id'], 'Counter' => ['id']] as $label => $fields) {
            foreach ($fields as $field) {
                $this->client->run("CREATE CONSTRAINT IF NOT EXISTS FOR (n:$label) REQUIRE n.$field IS UNIQUE");
            }
        }
        $this->client->run("UNWIND ['feed','social'] AS id MERGE (c:Counter {id:id}) ON CREATE SET c.value=0");
    }
}
