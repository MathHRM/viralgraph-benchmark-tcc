<?php

use App\Modules\Follows\Http\Controllers\FollowController;
use Illuminate\Support\Facades\Route;

Route::post('/follows', [FollowController::class, 'store']);
