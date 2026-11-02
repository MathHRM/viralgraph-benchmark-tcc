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
        $validated = $request->validate(['username' => ['required', 'string', 'max:255']]);
        $result = $users->create($validated['username']);

        return response()->json($result['body'], $result['status']);
    }
}
