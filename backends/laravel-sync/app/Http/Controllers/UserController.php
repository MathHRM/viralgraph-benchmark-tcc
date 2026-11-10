<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchUsersRequest;
use App\Http\Requests\ShowUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $service,
    ) {}

    public function search(SearchUsersRequest $request): JsonResponse
    {
        return response()->json($this->service->search(
            $request->string('s')->toString(),
            $request->input('cursor'),
        ));
    }

    public function create(StoreUserRequest $request): JsonResponse
    {
        $user = $this->service->create(
            $request->string('nome')->toString(),
            $request->string('apelido')->toString(),
            $request->string('nascimento')->toString(),
        );

        return response()->json(UserService::toApi($user), 201)
            ->header('Location', '/usuarios/'.$user->id);
    }

    public function show(ShowUserRequest $request, string $id): JsonResponse
    {
        return response()->json(UserService::toApi($this->service->show($id)));
    }
}
