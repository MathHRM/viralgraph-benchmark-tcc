<?php
declare(strict_types=1);
namespace App\Service;

use App\Exception\ApiError;
use App\Infrastructure\RabbitMQ\BrokerFactory;
use App\Repository\ShareRepository;
use App\Validation\Input;

final class ShareService
{
    public function __construct(private ShareRepository $shares, private BrokerFactory $brokers) {}

    /**
     * Aceitação assíncrona: valida contra o estado atual do Neo4j (uma consulta), publica o
     * evento com confirmação persistente do RabbitMQ e devolve a representação aceita (202).
     * A persistência e o fan-out ficam com o consumer em lote do worker.
     *
     * Duplicata já persistida retorna 422; duplicata ainda na fila é aceita e descartada pelo
     * consumer (SHARED.key é único), sem nova distribuição.
     */
    public function accept(array $values): array
    {
        $postId = $values['post_id'];
        $userId = $values['usuario_id'];
        $sourceId = $values['origem_usuario_id'] ?? null;
        $state = $this->shares->context($userId, $postId, $sourceId);
        if (!$state['user_exists'] || $state['author_id'] === null) {
            throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        }
        $authorId = $state['author_id'];
        $sourceId ??= $authorId;
        // Mesmas regras do síncrono: post no feed, origem = autor ou compartilhador já persistido.
        if ($authorId === $userId || $sourceId === $userId || $state['already_shared'] || !$state['in_feed']
            || ($sourceId !== $authorId && !$state['source_shared'])) {
            throw new ApiError();
        }
        $event = ['id' => Input::id(), 'usuario_id' => $userId, 'post_id' => $postId,
            'origem_usuario_id' => $sourceId, 'criado_em' => Input::now()];
        $broker = $this->brokers->open();
        try {
            $broker->publish('shares', $event);
        } finally {
            $broker->close();
        }
        return $event;
    }

    public function tree(string $postId): array
    {
        $result = $this->shares->tree($postId)
            ?? throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        $edges = $result['edges'];
        return [
            'post_id' => $postId,
            'autor_id' => $result['author_id'],
            'profundidade_maxima' => ShareRepository::TREE_DEPTH,
            'truncado' => count($edges) > ShareRepository::TREE_LIMIT,
            'data' => array_slice($edges, 0, ShareRepository::TREE_LIMIT),
        ];
    }
}
