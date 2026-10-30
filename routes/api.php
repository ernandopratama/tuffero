<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

// ==========================================
// V1 API ROUTES
// ==========================================

Route::prefix('v1')->group(function () {

    Route::get('/health', [HealthController::class, 'check']);

    // ------------------------------------------
    // Auth Routes (SPA / Sanctum)
    // ------------------------------------------
    Route::middleware(['web'])->prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/user', [AuthController::class, 'user']);
        });
    });
});
