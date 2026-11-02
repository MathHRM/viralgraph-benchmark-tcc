<?php

use App\Modules\Follows\Http\Controllers\FollowController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.auth')->group(function (): void {
    Route::post('/follows', [FollowController::class, 'store']);
    Route::delete('/follows/{user_id}', [FollowController::class, 'destroy']);
});
