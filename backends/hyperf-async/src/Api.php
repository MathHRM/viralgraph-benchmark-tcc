<?php
declare(strict_types=1);
namespace App;

final class Api
{
    public function handle(string $method, string $path, array $query, string $raw, string $type): array
    {
        $g = new Graph();
        if ($method === 'POST') {
            $fields = match ($path) {
                '/usuarios' => ['nome', 'apelido', 'nascimento'],
                '/seguir-usuario' => ['seguidor_id', 'seguido_id'],
                '/posts' => ['usuario_id', 'conteudo'],
                '/compartilhar-post' => ['usuario_id', 'post_id'],
                default => throw new ApiError(404, 'nao_encontrado', 'Rota não encontrada.'),
            };
            if ($query) {
                throw new ApiError();
            }
            $v = Input::body($raw, $type, $fields);
            $id = Input::id();
            $now = Input::now();
            switch ($path) {
                case '/usuarios':
                    $v = [
                        'id' => $id, 'nome' => Input::text($v['nome'], 32, true),
                        'apelido' => Input::text($v['apelido'], 100, true),
                        'nascimento' => Input::birth($v['nascimento'], getenv('REFERENCE_DATE') ?: '2026-11-08'),
                    ];
                    $g->run('CREATE (u:User $v)', ['v' => $v]);
                    return [201, $v, '/usuarios/'.$id];
                case '/seguir-usuario':
                    $g->get('User', $v['seguidor_id']);
                    $g->get('User', $v['seguido_id']);
                    if ($v['seguidor_id'] === $v['seguido_id']) {
                        throw new ApiError();
                    }
                    $result = ['id' => $id] + $v + ['criado_em' => $now];
                    $g->run('MATCH (clock:Counter {id:"social"}), (a:User {id:$v.seguidor_id}), (b:User {id:$v.seguido_id}) SET clock.value=clock.value+1 CREATE (a)-[:FOLLOWS]->(f:Follow $v)-[:TARGET]->(b) SET f.position=clock.value', ['v' => $result + ['key' => $v['seguidor_id'].':'.$v['seguido_id']]]);
                    return [201, $result, null];
                case '/posts':
                    $v['conteudo'] = Input::text($v['conteudo'], 500);
                    $g->get('User', $v['usuario_id']);
                    $broker = new Broker();
                    try {
                        $broker->publish('posts', ['id' => $id] + $v + ['criado_em' => $now]);
                    } finally {
                        $broker->close();
                    }
                    return [201, ['id' => $id], '/posts/'.$id];
                case '/compartilhar-post':
                    $g->get('User', $v['usuario_id']);
                    $post = $g->get('Post', $v['post_id']);
                    if ($post['usuario_id'] === $v['usuario_id']) {
                        throw new ApiError();
                    }
                    $result = ['id' => $id] + $v + ['criado_em' => $now];
                    $rows = $g->run('MATCH (e:Entry {key:$key}), (u:User {id:$actor}), (p:Post {id:$post}), (clock:Counter {id:"social"})
                        SET clock.value=clock.value+1
                        CREATE (u)-[:SHARED]->(s:Share $v)-[:ORIGINAL]->(p)
                        CREATE (t:Task {id:$id, post_id:$post, actor:$actor, kind:"compartilhamento",
                            cursor:"", published:false, done:false, follow_cutoff:clock.value})
                        RETURN s.id AS id', [
                        'key' => $v['usuario_id'].':'.$v['post_id'],
                        'v' => $result + ['key' => $v['usuario_id'].':'.$v['post_id']],
                        'id' => $id, 'post' => $v['post_id'], 'actor' => $v['usuario_id'],
                    ]);
                    if (!$rows) {
                        throw new ApiError();
                    }
                    return [201, $result, null];
            }
        }
        if ($method !== 'GET') {
            throw new ApiError(404, 'nao_encontrado', 'Rota não encontrada.');
        }
        if ($path === '/metricas') {
            if ($query) {
                throw new ApiError();
            }
            return [200, $g->metrics(), null];
        }
        if (preg_match('#^/(usuarios|posts)/([^/]+)$#D', $path, $m)) {
            if ($query) {
                throw new ApiError();
            }
            return [200, $g->get($m[1] === 'usuarios' ? 'User' : 'Post', Input::uuid($m[2])), null];
        }
        $feed = preg_match('#^/feed/([^/]+)$#D', $path, $m);
        if (!$feed && !in_array($path, ['/usuarios', '/posts'], true)) {
            throw new ApiError(404, 'nao_encontrado', 'Rota não encontrada.');
        }
        $termKey = $path === '/usuarios' ? 's' : 'c';
        if (array_diff(array_keys($query), $feed ? ['cursor'] : [$termKey, 'cursor'])) {
            throw new ApiError();
        }
        if (isset($query['cursor']) && !is_string($query['cursor'])) {
            throw new ApiError();
        }
        $term = $feed ? '' : Input::text($query[$termKey] ?? null, $termKey === 's' ? 100 : 500);
        $scope = $feed ? 'feed:'.Input::uuid($m[1]) : $path.':'.$term;
        $cursor = Input::cursor($query['cursor'] ?? null, $scope);
        if ($feed) {
            $uid = Input::uuid($m[1]);
            $g->get('User', $uid);
            $rows = $g->run('MATCH (e:Entry {usuario_id:$uid}), (p:Post {id:e.post_id})
                WHERE $seq IS NULL OR e.seq < $seq OR (e.seq=$seq AND p.id < $last)
                RETURN properties(p) AS post, e.origem AS origem, e.autor_evento_id AS autor_evento_id,
                    e.evento_id AS evento_id, e.seq AS seq
                ORDER BY seq DESC, post.id DESC LIMIT 51', [
                'uid' => $uid, 'seq' => $cursor[1] ?? null, 'last' => $cursor[2] ?? '',
            ]);
        } else {
            $label = $path === '/usuarios' ? 'User' : 'Post';
            $filter = $label === 'User' ? '(n.nome CONTAINS $term OR n.apelido CONTAINS $term)' : 'n.conteudo CONTAINS $term';
            $rows = $g->run("MATCH (n:$label) WHERE $filter AND n.id > \$last RETURN properties(n) AS item ORDER BY n.id LIMIT 51", [
                'term' => $term, 'last' => $cursor[2] ?? '',
            ]);
        }
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $last = $rows ? $rows[count($rows) - 1] : null;
        $next = $more ? base64_encode(json_encode([$scope, $feed ? $last['seq'] : 0, $feed ? $last['post']['id'] : $last['item']['id']], JSON_THROW_ON_ERROR)) : null;
        $data = array_map(function ($r) use ($feed) {
            if (!$feed) {
                return $r['item'];
            }
            unset($r['seq']);
            return $r;
        }, $rows);
        return [200, ['data' => $data, 'proximo_cursor' => $next], null];
    }
}
