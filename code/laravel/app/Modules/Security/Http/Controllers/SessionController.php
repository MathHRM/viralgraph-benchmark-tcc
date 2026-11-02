<?php

namespace App\Modules\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Security\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function store(Request $request, SessionService $sessions): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);
        $identity = $sessions->login($validated['username'], $validated['password']);

        if ($identity === null) {
            return response()->json(['error' => [
                'code' => 'invalid_credentials',
                'message' => 'The username or password is incorrect.',
            ]], 401);
        }

        $request->session()->regenerate();
        $request->session()->put('user_id', $identity['id']);

        return response()->json(['data' => $identity]);
    }

    public function show(Request $request, SessionService $sessions): JsonResponse
    {
        $identity = $sessions->identity($request->session()->get('user_id'));

        if ($identity === null) {
            return response()->json(['error' => [
                'code' => 'unauthenticated',
                'message' => 'Authentication is required.',
            ]], 401);
        }

        return response()->json(['data' => $identity]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['status' => 'logged_out']]);
    }
}
