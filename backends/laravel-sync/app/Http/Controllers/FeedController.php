<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\FeedService;
use Illuminate\Http\JsonResponse;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $service,
    ) {}

    public function index(string $id): JsonResponse
    {
        return response()->json([
            'erro' => [
                'codigo' => 'nao_implementado',
                'mensagem' => 'A consulta paginada do feed aguarda a definição da sequência lógica dos eventos.',
            ],
        ], 501);
    }
}
