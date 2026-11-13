<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class EnsureJsonApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $contentType = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));

        if ($contentType !== 'application/json') {
            return response()->json([
                'erro' => [
                    'codigo' => 'tipo_midia_nao_aceito',
                    'mensagem' => 'Envie o corpo com Content-Type: application/json.',
                ],
            ], 415);
        }

        $content = $request->getContent();

        if ($content !== '') {
            try {
                json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return response()->json([
                    'erro' => [
                        'codigo' => 'json_malformado',
                        'mensagem' => 'O corpo da requisição contém JSON malformado.',
                    ],
                ], 400);
            }
        }

        return $next($request);
    }
}
