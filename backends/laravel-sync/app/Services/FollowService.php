<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Follow;
use App\Models\User;
use App\Repositories\FollowRepository;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class FollowService
{
    public function __construct(
        private readonly FollowRepository $follows,
        private readonly UserRepository $users,
    ) {}

    public function create(string $followerId, string $followedId): Follow
    {
        $this->requireUser($followerId);
        $this->requireUser($followedId);

        if ($followerId === $followedId) {
            throw ValidationException::withMessages([
                'seguido_id' => ['Um usuário não pode seguir a si próprio.'],
            ]);
        }

        try {
            return $this->follows->create($followerId, $followedId);
        } catch (QueryException $exception) {
            if (UserService::isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'seguido_id' => ['Esta relação já existe.'],
                ]);
            }

            throw $exception;
        }
    }

    private function requireUser(string $id): void
    {
        if (! $this->users->exists($id)) {
            throw (new ModelNotFoundException)
                ->setModel(User::class, [$id]);
        }
    }
}
