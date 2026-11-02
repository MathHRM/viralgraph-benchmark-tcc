<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function store(Request $request, UserService $users): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);
        $user = $users->create($validated['username'], $validated['password']);

        return response()->json(['data' => ['id' => $user->id, 'username' => $user->username]], 201);
    }

    public function index(Request $request, UserService $users): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($users->search(
            $validated['search'] ?? '',
            (int) ($validated['limit'] ?? 20),
            $validated['cursor'] ?? null,
            (int) $request->session()->get('user_id'),
        ));
    }
}
