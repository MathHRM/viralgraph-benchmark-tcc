<?php

use App\Modules\Shares\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.auth')->group(function (): void {
    Route::post('/shares', [ShareController::class, 'store']);
});
