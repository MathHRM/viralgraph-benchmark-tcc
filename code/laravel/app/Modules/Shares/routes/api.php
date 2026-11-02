<?php

use App\Modules\Shares\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;

Route::post('/shares', [ShareController::class, 'store']);
