<?php

namespace App\Modules\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('user_id')) {
            return response()->json(['error' => [
                'code' => 'unauthenticated',
                'message' => 'Authentication is required.',
            ]], 401);
        }

        return $next($request);
    }
}
