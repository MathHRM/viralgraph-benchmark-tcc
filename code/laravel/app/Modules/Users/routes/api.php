<?php

use App\Modules\Security\Http\Controllers\SessionController;
use App\Modules\Users\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/users', [UserController::class, 'store']);
Route::post('/sessions', [SessionController::class, 'store']);
Route::get('/session', [SessionController::class, 'show']);
Route::delete('/session', [SessionController::class, 'destroy']);
Route::middleware('api.auth')->group(function (): void {
    Route::get('/users', [UserController::class, 'index']);
});
