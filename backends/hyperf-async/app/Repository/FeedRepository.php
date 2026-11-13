<?php
declare(strict_types=1);
namespace App\Repository;

use App\Exception\ApiError;
use App\Infrastructure\Neo4j\Neo4jClient;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Coroutine\Exception\ParallelExecutionException;
use Hyperf\Coroutine\Parallel;

final class FeedRepository
{
    public function __construct(private Neo4jClient $client) {}

    /**
     * Percorre só as relações FEED do usuário (index-free adjacency): custo proporcional
     * ao feed dele, não ao total de entradas do banco. seq é única por usuário.
     *
     * FEED_PARALLEL (padrão desligado, mantém o baseline) divide a leitura em duas corrotinas:
     * (a) existência do usuário e (b) página de entradas. Dobra os round-trips de leitura; o
     * ganho aparece quando há capacidade ociosa no Neo4j. A resposta é idêntica ao contrato
     * ({data, proximo_cursor}, 404 para usuário inexistente, mesma ordenação e cursor).
     */
    public function page(string $uid, ?array $cursor): array
    {
        if ($this->parallelEnabled()) {
            return $this->pageParallel($uid, $cursor);
        }
        return $this->pageSingle($uid, $cursor);
    }

    /** Consulta única original: existência e página resolvidas na mesma transação. */
    private function pageSingle(string $uid, ?array $cursor): array
    {
        $rows = $this->client->run('MATCH (u:User {id:$uid})
            RETURN u.id AS user_id, COLLECT {
                MATCH (u)-[e:FEED]->(p:Post)
                WHERE $seq IS NULL OR e.seq < $seq OR (e.seq=$seq AND p.id < $last)
                RETURN {post: properties(p), origem: e.origem, autor_evento_id: e.autor_evento_id,
                    evento_id: e.evento_id, seq: e.seq}
                ORDER BY e.seq DESC, p.id DESC LIMIT 51
            } AS entries', [
            'uid' => $uid, 'seq' => $cursor[1] ?? null, 'last' => $cursor[2] ?? '',
        ]);
        if (!$rows) {
            throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        }
        return $rows[0]['entries'];
    }

    /**
     * Variante paralela: existência e página em corrotinas simultâneas. Fora de corrotina cai
     * no caminho único (não há escalonador para paralelizar). A existência tem precedência: se o
     * usuário não existe, 404 — independentemente do resultado da página.
     */
    private function pageParallel(string $uid, ?array $cursor): array
    {
        if (!Coroutine::inCoroutine()) {
            return $this->pageSingle($uid, $cursor);
        }

        $parallel = new Parallel();
        $parallel->add(fn () => $this->client->run(
            'MATCH (u:User {id:$uid}) RETURN u.id AS id', ['uid' => $uid]
        ), 'exists');
        $parallel->add(fn () => $this->client->run('MATCH (u:User {id:$uid})-[e:FEED]->(p:Post)
            WHERE $seq IS NULL OR e.seq < $seq OR (e.seq=$seq AND p.id < $last)
            RETURN {post: properties(p), origem: e.origem, autor_evento_id: e.autor_evento_id,
                evento_id: e.evento_id, seq: e.seq} AS entry
            ORDER BY e.seq DESC, p.id DESC LIMIT 51', [
            'uid' => $uid, 'seq' => $cursor[1] ?? null, 'last' => $cursor[2] ?? '',
        ]), 'entries');

        try {
            $results = $parallel->wait();
        } catch (ParallelExecutionException $failure) {
            // Qualquer falha de transporte/consulta propaga a primeira causa, como no caminho único.
            throw array_values($failure->getThrowables())[0];
        }

        if (!$results['exists']) {
            throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        }
        return array_map(static fn (array $row) => $row['entry'], $results['entries']);
    }

    private function parallelEnabled(): bool
    {
        return in_array(strtolower((string) getenv('FEED_PARALLEL')), ['1', 'true', 'on', 'yes'], true);
    }
}
