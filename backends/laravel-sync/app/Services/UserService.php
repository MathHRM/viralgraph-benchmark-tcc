<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\CursorCodec;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private readonly UserRepository $users) {}

    public function create(string $name, string $nickname, string $birthDate): User
    {
        try {
            return $this->users->create([
                'name' => $name,
                'nickname' => $nickname,
                'birth_date' => $birthDate,
            ]);
        } catch (QueryException $exception) {
            if (self::isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'apelido' => ['Este apelido já está em uso.'],
                ]);
            }

            throw $exception;
        }
    }

    public function show(string $id): User
    {
        return $this->users->find($id) ?? throw (new ModelNotFoundException)
            ->setModel(User::class, [$id]);
    }

    /** @return array{data: list<array<string, mixed>>, proximo_cursor: ?string} */
    public function search(string $term, ?string $cursor): array
    {
        $result = $this->users->search($term, CursorCodec::decode($cursor));

        return [
            'data' => $result['items']->map(fn (User $user): array => self::toApi($user))->all(),
            'proximo_cursor' => $result['next_cursor'] === null
                ? null
                : CursorCodec::encode($result['next_cursor']),
        ];
    }

    /** @return array{id: string, nome: string, apelido: string, nascimento: string} */
    public static function toApi(User $user): array
    {
        return [
            'id' => $user->id,
            'nome' => $user->name,
            'apelido' => $user->nickname,
            'nascimento' => $user->birth_date->format('Y-m-d'),
        ];
    }

    public static function isUniqueViolation(QueryException $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException
            || ($exception->errorInfo[1] ?? null) === 1062
            || $exception->getCode() === '23505';
    }
}
