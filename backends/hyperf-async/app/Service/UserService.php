<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\UserRepository;
use App\Validation\Input;

final class UserService
{
    public function __construct(private UserRepository $users) {}

    public function create(array $values): array
    {
        $user = [
            'id' => Input::id(), 'nome' => $values['nome'], 'apelido' => $values['apelido'],
            'nascimento' => Input::birth($values['nascimento'], getenv('REFERENCE_DATE') ?: '2026-11-08'),
        ];
        $this->users->create($user);
        return $user;
    }
}
