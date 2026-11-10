<?php
declare(strict_types=1);
namespace App\Repository;

use App\Infrastructure\Neo4j\Neo4jClient;

final class FollowRepository
{
    public function __construct(private Neo4jClient $client) {}

    public function create(array $result): array
    {
        return $this->client->run('MATCH (clock:Counter {id:"social"})
            OPTIONAL MATCH (a:User {id:$v.seguidor_id})
            OPTIONAL MATCH (b:User {id:$v.seguido_id})
            WITH clock, a, b
            FOREACH (_ IN CASE WHEN a IS NOT NULL AND b IS NOT NULL AND a <> b THEN [1] ELSE [] END |
                SET clock.value=clock.value+1
                CREATE (a)-[:FOLLOWS]->(f:Follow $v)-[:TARGET]->(b)
                SET f.position=clock.value)
            RETURN a IS NOT NULL AS seguidor_existe, b IS NOT NULL AS seguido_existe', [
                'v' => $result + ['key' => $result['seguidor_id'].':'.$result['seguido_id']],
            ])[0] ?? ['seguidor_existe' => false, 'seguido_existe' => false];
    }
}
