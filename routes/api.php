<?php

use App\Http\Controllers\Api\AnemometerController;
use App\Http\Controllers\Api\AnemometerReadingController;
use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\ReadingController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — parity with Django config/api_router.py
|--------------------------------------------------------------------------
|
| Intentionally omitted (parity with the `main` branch of the Django repo):
|   - GET /api/readings/export  (Part-1 gap the candidate fills)
|   - ?anemometer= filter on /api/readings
|
*/

Route::post('auth-token', AuthTokenController::class);

Route::middleware('auth:sanctum')->group(function () {
    // users
    Route::get('users/me', [UserController::class, 'me']);
    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{username}', [UserController::class, 'show']);
    Route::patch('users/{username}', [UserController::class, 'update']);

    // anemometers (custom action registered BEFORE the resource so
    // `/anemometers/recent-readings` isn't caught by the {id} wildcard).
    Route::get('anemometers/recent-readings', [AnemometerController::class, 'recentReadings']);
    Route::apiResource('anemometers', AnemometerController::class);

    // nested readings under anemometer
    Route::get('anemometers/{anemometer}/readings', [AnemometerReadingController::class, 'index']);
    Route::get('anemometers/{anemometer}/readings/{reading}', [AnemometerReadingController::class, 'show']);

    // readings — export registered BEFORE apiResource so "export" isn't
    // captured by the {reading} wildcard (same pattern as recent-readings).
    Route::get('readings/export', [ReadingController::class, 'export']);
    Route::apiResource('readings', ReadingController::class);
});
