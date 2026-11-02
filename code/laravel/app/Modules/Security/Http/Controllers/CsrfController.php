<?php

namespace App\Modules\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Security\Services\CsrfService;
use Illuminate\Http\JsonResponse;

class CsrfController extends Controller
{
    public function show(CsrfService $csrf): JsonResponse
    {
        return response()->json(['data' => ['token' => $csrf->token()]]);
    }
}
