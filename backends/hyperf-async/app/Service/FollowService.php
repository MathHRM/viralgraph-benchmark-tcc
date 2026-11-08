<?php
declare(strict_types=1);
namespace App\Service;

use App\Exception\ApiError;
use App\Repository\FollowRepository;
use App\Repository\UserRepository;
use App\Validation\Input;

final class FollowService
{
    public function __construct(private UserRepository $users, private FollowRepository $follows) {}

    public function create(array $values): array
    {
        $this->users->get($values['seguidor_id']);
        $this->users->get($values['seguido_id']);
        if ($values['seguidor_id'] === $values['seguido_id']) {
            throw new ApiError();
        }
        $result = ['id' => Input::id()] + $values + ['criado_em' => Input::now()];
        $this->follows->create($result);
        return $result;
    }
}
