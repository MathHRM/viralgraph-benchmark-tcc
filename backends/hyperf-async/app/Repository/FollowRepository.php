<?php
declare(strict_types=1);
namespace App\Repository;

use App\Infrastructure\Neo4j\Neo4jClient;

final class FollowRepository
{
    public function __construct(private Neo4jClient $client) {}

    public function create(array $result): void
    {
        $this->client->run('MATCH (clock:Counter {id:"social"}), (a:User {id:$v.seguidor_id}), (b:User {id:$v.seguido_id}) SET clock.value=clock.value+1 CREATE (a)-[:FOLLOWS]->(f:Follow $v)-[:TARGET]->(b) SET f.position=clock.value', ['v' => $result + ['key' => $result['seguidor_id'].':'.$result['seguido_id']]]);
    }
}
