<?php
declare(strict_types=1);
namespace App\Controller;

use App\Exception\ApiError;

final class NotFoundController
{
    public function index(): never
    {
        throw new ApiError(404, 'nao_encontrado', 'Rota não encontrada.');
    }
}
