<?php
declare(strict_types=1);
namespace App\Repository;

use App\Infrastructure\Neo4j\Neo4jClient;

final class MetricsRepository
{
    public function __construct(private Neo4jClient $client) {}

    public function metrics(): array
    {
        $r = $this->client->run('CALL { MATCH (u:User) RETURN count(u) AS usuarios }
            CALL { MATCH (f:Follow) RETURN count(f) AS relacoes_seguir }
            CALL { MATCH (p:Post) RETURN count(p) AS posts_criados }
            CALL { MATCH (s:Share) RETURN count(s) AS compartilhamentos }
            CALL { MATCH (e:Entry) RETURN count(e) AS entradas_feed }
            RETURN usuarios, usuarios AS feeds, relacoes_seguir, posts_criados, compartilhamentos, entradas_feed')[0];
        $r['inserts_logicos'] = array_sum($r);
        return $r;
    }
}
