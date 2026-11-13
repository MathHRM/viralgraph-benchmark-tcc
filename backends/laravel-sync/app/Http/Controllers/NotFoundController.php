<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class NotFoundController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'erro' => [
                'codigo' => 'nao_encontrado',
                'mensagem' => 'Recurso não encontrado.',
            ],
        ], 404);
    }
}
