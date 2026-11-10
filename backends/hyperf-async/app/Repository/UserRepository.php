<?php
declare(strict_types=1);
namespace App\Repository;

use App\Exception\ApiError;
use App\Infrastructure\Neo4j\Neo4jClient;

final class UserRepository
{
    private const CACHE_LIMIT = 256;

    /** @var array<string, array> */
    private array $cache = [];

    /** @var list<string> */
    private array $cacheOrder = [];

    public function __construct(private Neo4jClient $client) {}

    public function get(string $id): array
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $rows = $this->client->run("MATCH (n:User {id:\$id}) RETURN properties(n) AS n", ['id' => $id]);
        if (!$rows) {
            throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        }

        $user = $rows[0]['n'];
        $this->remember($id, $user);
        return $user;
    }

    public function search(string $term, ?array $cursor): array
    {
        return $this->client->run('MATCH (n:User) WHERE (n.nome CONTAINS $term OR n.apelido CONTAINS $term) AND n.id > $last RETURN properties(n) AS item ORDER BY n.id LIMIT 51', [
            'term' => $term, 'last' => $cursor[2] ?? '',
        ]);
    }

    public function create(array $user): void
    {
        $this->client->run('CREATE (u:User $v)', ['v' => $user]);
        $this->remember($user['id'], $user);
    }

    private function remember(string $id, array $user): void
    {
        if (!array_key_exists($id, $this->cache)) {
            $this->cacheOrder[] = $id;
        }

        $this->cache[$id] = $user;
        if (count($this->cacheOrder) > self::CACHE_LIMIT) {
            unset($this->cache[array_shift($this->cacheOrder)]);
        }
    }
}
