<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    /** @param array<string, list<string>> $fields */
    public static function validationError(array $fields, string $message = 'Dados inválidos.'): JsonResponse
    {
        return response()->json([
            'erro' => [
                'codigo' => 'validacao',
                'mensagem' => $message,
                'campos' => (object) $fields,
            ],
        ], 422);
    }

    public static function notFound(string $message = 'Recurso não encontrado.'): JsonResponse
    {
        return response()->json([
            'erro' => [
                'codigo' => 'nao_encontrado',
                'mensagem' => $message,
            ],
        ], 404);
    }

    public static function unavailable(string $message = 'Serviço temporariamente indisponível.'): JsonResponse
    {
        return response()->json([
            'erro' => [
                'codigo' => 'falha_operacional',
                'mensagem' => $message,
            ],
        ], 503);
    }
}
