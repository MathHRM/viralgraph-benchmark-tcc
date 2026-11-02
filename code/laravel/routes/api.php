<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

require app_path('Modules/Users/routes/api.php');
require app_path('Modules/Follows/routes/api.php');
require app_path('Modules/Posts/routes/api.php');
require app_path('Modules/Shares/routes/api.php');
