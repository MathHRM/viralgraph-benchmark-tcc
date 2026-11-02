<?php

namespace App\Modules\Feed\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Feed\Services\FeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    public function index(Request $request, FeedService $feed): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($feed->page((int) $request->session()->get('user_id'), (int) ($validated['limit'] ?? 20), $validated['cursor'] ?? null));
    }
}
