<?php

use App\Modules\Posts\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.auth')->group(function (): void {
    Route::post('/posts', [PostController::class, 'store']);
});
