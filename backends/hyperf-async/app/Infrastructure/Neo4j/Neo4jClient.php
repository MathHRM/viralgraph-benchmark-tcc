<?php
declare(strict_types=1);
namespace App\Infrastructure\Neo4j;

use App\Exception\ApiError;

class Neo4jClient
{
    /** Cada chamada é uma transação atômica; o handle pertence apenas à chamada. */
    public function run(string $query, array $params = []): array
    {
        $ch = curl_init((getenv('NEO4J_URL') ?: 'http://neo4j:7474').'/db/neo4j/tx/commit');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => 'neo4j:'.(getenv('NEO4J_PASSWORD') ?: 'benchmark-local'),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['statements' => [['statement' => $query, 'parameters' => (object) $params]]], JSON_THROW_ON_ERROR),
        ]);
        try {
            $raw = curl_exec($ch);
            if ($raw === false || curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new \RuntimeException('Neo4j indisponível.');
            }
            $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if ($result['errors']) {
                $error = $result['errors'][0];
                if ($error['code'] === 'Neo.ClientError.Schema.ConstraintValidationFailed') {
                    throw new ApiError();
                }
                throw new \RuntimeException($error['code'].': '.$error['message']);
            }
            $r = $result['results'][0];
            return array_map(fn ($row) => array_combine($r['columns'], $row['row']), $r['data']);
        } finally {
            curl_close($ch);
        }
    }
}
