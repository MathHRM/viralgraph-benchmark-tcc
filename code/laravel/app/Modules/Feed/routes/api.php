<?php

use App\Modules\Feed\Http\Controllers\FeedController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.auth')->get('/feed', [FeedController::class, 'index']);
