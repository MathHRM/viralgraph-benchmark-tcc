<?php
declare(strict_types=1);
namespace App;

final class Graph
{
    /** Cada chamada é uma transação atômica no Neo4j. Não compartilhar handles entre corrotinas. */
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

    public function initialize(): void
    {
        foreach (['User' => ['id', 'apelido'], 'Follow' => ['id', 'key'], 'Post' => ['id'], 'Share' => ['id', 'key'], 'Entry' => ['key'], 'Origin' => ['key'], 'Task' => ['id'], 'Counter' => ['id']] as $label => $fields) {
            foreach ($fields as $field) {
                $this->run("CREATE CONSTRAINT IF NOT EXISTS FOR (n:$label) REQUIRE n.$field IS UNIQUE");
            }
        }
        $this->run("UNWIND ['feed','social'] AS id MERGE (c:Counter {id:id}) ON CREATE SET c.value=0");
    }

    public function get(string $label, string $id): array
    {
        $rows = $this->run("MATCH (n:$label {id:\$id}) RETURN properties(n) AS n", ['id' => $id]);
        if (!$rows) {
            throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        }
        return $rows[0]['n'];
    }

    public function persistPosts(array $posts): void
    {
        $this->run('MATCH (clock:Counter {id:"social"}) SET clock.value=clock.value+1
            WITH clock UNWIND $posts AS p
            MATCH (u:User {id:p.usuario_id})
            MERGE (n:Post {id:p.id})
            ON CREATE SET n.usuario_id=p.usuario_id, n.conteudo=p.conteudo, n.criado_em=p.criado_em
            MERGE (u)-[:AUTHORED]->(n)
            MERGE (t:Task {id:p.id})
            ON CREATE SET t.post_id=p.id, t.actor=p.usuario_id, t.kind="publicacao",
                t.cursor="", t.published=false, t.done=false, t.follow_cutoff=clock.value', ['posts' => $posts]);
    }

    /** Cursor e entregas são confirmados juntos; reentrega não muda origem nem posição. */
    public function distribute(string $id, int $size): bool
    {
        $rows = $this->run('MATCH (t:Task {id:$id}), (p:Post {id:t.post_id}), (counter:Counter {id:"feed"})
            SET counter.value=counter.value+1
            WITH t,p,counter
            MATCH (recipientUser:User)-[:FOLLOWS]->(f:Follow)-[:TARGET]->(:User {id:t.actor})
            WHERE f.seguidor_id > t.cursor AND f.seguidor_id <> p.usuario_id AND f.position <= t.follow_cutoff AND t.done=false
            WITH t,p,counter,f ORDER BY f.seguidor_id LIMIT $size
            WITH t,p,counter,collect(f.seguidor_id) AS recipients
            UNWIND range(0,size(recipients)-1) AS i
            WITH t,p,counter,recipients,i,recipients[i] AS recipient
            MERGE (e:Entry {key:recipient+":"+p.id})
            ON CREATE SET e.usuario_id=recipient, e.post_id=p.id, e.seq=counter.value+i,
                e.origem=t.kind, e.autor_evento_id=t.actor, e.evento_id=t.id
            MERGE (o:Origin {key:recipient+":"+p.id+":"+t.id})
            ON CREATE SET o.usuario_id=recipient, o.post_id=p.id, o.evento_id=t.id,
                o.origem=t.kind, o.autor_evento_id=t.actor
            WITH t,counter,recipients,count(*) AS written
            SET counter.value=counter.value+size(recipients), t.cursor=last(recipients),
                t.done=size(recipients)<$size
            RETURN t.done AS done', ['id' => $id, 'size' => $size]);
        if (!$rows) {
            $this->run('MATCH (t:Task {id:$id}) SET t.done=true', ['id' => $id]);
            return true;
        }
        return $rows[0]['done'];
    }

    public function metrics(): array
    {
        $r = $this->run('CALL { MATCH (u:User) RETURN count(u) AS usuarios }
            CALL { MATCH (f:Follow) RETURN count(f) AS relacoes_seguir }
            CALL { MATCH (p:Post) RETURN count(p) AS posts_criados }
            CALL { MATCH (s:Share) RETURN count(s) AS compartilhamentos }
            CALL { MATCH (e:Entry) RETURN count(e) AS entradas_feed }
            RETURN usuarios, usuarios AS feeds, relacoes_seguir, posts_criados, compartilhamentos, entradas_feed')[0];
        $r['inserts_logicos'] = array_sum($r);
        return $r;
    }
}
