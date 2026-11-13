<?php
declare(strict_types=1);
namespace App\Infrastructure\Neo4j;

use Hyperf\Coroutine\Coroutine;
use Hyperf\Engine\Channel;

/**
 * Pool de permissões (não de handles) por processo. Limita quantas transações Neo4j
 * simultâneas um processo pode ter em voo, alinhado a server.threads.worker_count do banco
 * (NEO4J_HTTP_THREADS). Pool cheio => a corrotina aguarda no Channel do Swoole (backpressure
 * natural), em vez de inundar o Jetty com requisições que ele não tem threads para atender.
 * Os handles cURL keep-alive continuam vivendo em Neo4jClient; este pool só controla quantas
 * corrotinas usam o transporte ao mesmo tempo.
 *
 * O Channel do Swoole só pode ser operado dentro de uma corrotina. Por isso ele é criado e
 * preenchido preguiçosamente na primeira aquisição em contexto de corrotina. Fora de corrotina
 * (construção pelo container, bin/init.php, setup do worker, testes CLI com stubs) acquire() e
 * release() são no-op transparentes: não há escalonador para suspender.
 */
final class Neo4jPool
{
    private const DEFAULT_SIZE = 32;

    private readonly int $size;

    /** @var Channel<bool>|null Criado na primeira aquisição dentro de corrotina. */
    private ?Channel $permits = null;

    public function __construct(?int $size = null)
    {
        $resolved = $size ?? (int) (getenv('NEO4J_POOL_SIZE') ?: self::DEFAULT_SIZE);
        $this->size = max(1, $resolved);
    }

    public function size(): int
    {
        return $this->size;
    }

    /** Permissões disponíveis; reporta a capacidade cheia enquanto o canal não foi iniciado. */
    public function available(): int
    {
        return $this->permits?->getLength() ?? $this->size;
    }

    /**
     * Adquire uma permissão. Dentro de corrotina, suspende até haver vaga (backpressure).
     * Fora de corrotina, retorna imediatamente sem contabilizar.
     */
    public function acquire(): void
    {
        if (!Coroutine::inCoroutine()) {
            return;
        }
        $this->channel()->pop();
    }

    /** Devolve a permissão adquirida; no-op fora de corrotina ou antes da primeira aquisição. */
    public function release(): void
    {
        if (!Coroutine::inCoroutine() || $this->permits === null) {
            return;
        }
        $this->permits->push(true);
    }

    /**
     * Garante o canal preenchido na capacidade. Chamado apenas dentro de corrotina; como a
     * capacidade é igual ao número de tokens, os push de iniciação nunca bloqueiam.
     */
    private function channel(): Channel
    {
        if ($this->permits === null) {
            $this->permits = new Channel($this->size);
            for ($i = 0; $i < $this->size; $i++) {
                $this->permits->push(true);
            }
        }
        return $this->permits;
    }
}
