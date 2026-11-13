<?php
declare(strict_types=1);
/**
 * Configuração do transporte Neo4j, centralizando os parâmetros antes espalhados em getenv().
 * A fonte única é o ambiente: Neo4jClient e Neo4jPool leem as mesmas variáveis diretamente para
 * permanecerem construíveis sem o container (DI e substitutos de teste que estendem Neo4jClient).
 * Este arquivo expõe os valores pela ConfigInterface do Hyperf, no padrão de config/autoload/.
 *
 * - url:        endpoint HTTP transacional (NEO4J_URL).
 * - timeout_ms: teto por transação em milissegundos (NEO4J_TIMEOUT_MS), mínimo efetivo 1800.
 * - pool_size:  permissões de concorrência por processo (NEO4J_POOL_SIZE). Padrão 32, espelhando
 *               server.threads.worker_count do banco (NEO4J_HTTP_THREADS): pool cheio gera
 *               backpressure na aplicação em vez de inundar o Jetty do Neo4j.
 */
return [
    'url' => getenv('NEO4J_URL') ?: 'http://neo4j:7474',
    'timeout_ms' => max(1_800, (int) (getenv('NEO4J_TIMEOUT_MS') ?: 1_800)),
    'pool_size' => max(1, (int) (getenv('NEO4J_POOL_SIZE') ?: 32)),
];
