<?php
declare(strict_types=1);
namespace App\Service;

use App\Exception\ApiError;
use App\Repository\FollowRepository;
use App\Validation\Input;

final class FollowService
{
    public function __construct(private FollowRepository $follows) {}

    public function create(array $values): array
    {
        $result = ['id' => Input::id()] + $values + ['criado_em' => Input::now()];
        $existence = $this->follows->create($result);
        if (!$existence['seguidor_existe'] || !$existence['seguido_existe']) {
            throw new ApiError(404, 'nao_encontrado', 'Recurso não encontrado.');
        }
        if ($values['seguidor_id'] === $values['seguido_id']) {
            throw new ApiError();
        }
        return $result;
    }
}
