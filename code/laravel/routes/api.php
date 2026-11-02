<?php

use App\Modules\Security\Http\Controllers\CsrfController;
use App\Modules\Shared\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/metrics', MetricsController::class);

    Route::get('/health', function () {
        return response()->json(['status' => 'ok']);
    });

    Route::get('/csrf', [CsrfController::class, 'show']);

    require app_path('Modules/Users/routes/api.php');
    require app_path('Modules/Follows/routes/api.php');
    require app_path('Modules/Posts/routes/api.php');
    require app_path('Modules/Shares/routes/api.php');
    require app_path('Modules/Feed/routes/api.php');
    require app_path('Modules/Operations/routes/api.php');
});
