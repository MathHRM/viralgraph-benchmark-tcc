<?php

declare(strict_types=1);

use App\Http\Controllers\FeedController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NotFoundController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureJsonApiRequest;
use Illuminate\Support\Facades\Route;

Route::get('/usuarios', [UserController::class, 'search']);
Route::get('/usuarios/{id}', [UserController::class, 'show']);
Route::get('/posts', [PostController::class, 'search']);
Route::get('/posts/{id}', [PostController::class, 'show']);
Route::get('/feed/{id}', [FeedController::class, 'index']);
Route::get('/metricas', [MetricsController::class, 'index']);

Route::middleware(EnsureJsonApiRequest::class)->group(function (): void {
    Route::post('/usuarios', [UserController::class, 'create']);
    Route::post('/seguir-usuario', [FollowController::class, 'create']);
    Route::post('/posts', [PostController::class, 'create']);
    Route::post('/compartilhar-post', [ShareController::class, 'create']);
});

// Preserva o 404 histórico destas combinações; outros métodos continuam retornando 405.
foreach (['/seguir-usuario', '/compartilhar-post'] as $path) {
    Route::get($path, [NotFoundController::class, 'index']);
}
foreach (['/usuarios/{id}', '/posts/{id}', '/feed/{id}', '/metricas'] as $path) {
    Route::post($path, [NotFoundController::class, 'index']);
}
