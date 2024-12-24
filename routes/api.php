<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\WeaponDeliveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('weapons-delivery')->group(function () {
    Route::middleware(['auth:sanctum'])->post('/', [WeaponDeliveryController::class, 'store']);
});

Route::prefix('users')->group(function () {
    Route::middleware(['guest', 'throttle:6,1'])->post('/login/social', [UserController::class, 'social_login']);
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/me', [UserController::class, 'me']);
        Route::post('/logout', [UserController::class, 'logout']);
    });
});
