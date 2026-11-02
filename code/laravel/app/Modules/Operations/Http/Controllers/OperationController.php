<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationController extends Controller
{
    public function show(Request $request, string $operation_id, OperationService $operations): JsonResponse
    {
        $operation = $operations->find($operation_id, (int) $request->session()->get('user_id'));
        if ($operation === null) {
            return response()->json(['error' => ['code' => 'operation_not_found', 'message' => 'Operation not found.']], 404);
        }

        return response()->json(['data' => $operation]);
    }
}
