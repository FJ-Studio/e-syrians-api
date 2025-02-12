<?php

use App\Http\Controllers\PollController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeaponDeliveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('weapons-delivery')->group(function () {
    Route::middleware(['auth:sanctum'])->post('/', [WeaponDeliveryController::class, 'store']);
});

Route::prefix('users')->group(function () {
    Route::middleware(['guest', 'throttle:6,1'])->post('/register', [UserController::class, 'store']);
    Route::middleware(['guest', 'throttle:6,1'])->post('/login', [UserController::class, 'login']);
    Route::middleware(['guest', 'throttle:6,1'])->post('/login/social', [UserController::class, 'social_login']);
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/me', [UserController::class, 'me']);
        Route::post('/logout', [UserController::class, 'logout']);
        Route::post('/update/basic-info', [UserController::class, 'update_basic_info']);
    });
});

Route::prefix('polls')->group(function () {
    Route::middleware(['auth:sanctum'])->post('/', [PollController::class, 'store']);
    Route::get('/{poll}', [PollController::class, 'show']);
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [PollController::class, 'index']);
        Route::put('/{poll}', [PollController::class, 'update']);
        Route::delete('/{poll}', [PollController::class, 'destroy']);
    });
});

Route::get('/stats', [StatsController::class, 'index'])->middleware((['throttle:10,1']));
