<?php

use App\Modules\Operations\Http\Controllers\OperationController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.auth')->get('/operations/{operation_id}', [OperationController::class, 'show']);
