<?php

use App\Http\Controllers\PollController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeaponDeliveryController;
use App\Http\Middleware\CanVerify;
use App\Http\Middleware\UserIsVerified;
use Illuminate\Support\Facades\Route;

Route::prefix('weapons-delivery')->group(function () {
    Route::middleware(['auth:sanctum'])->post('/', [WeaponDeliveryController::class, 'store']);
});

Route::prefix('users')->group(function () {
    Route::get('/first', [UserController::class, 'first']);
    Route::get('/verify/{user:uuid}', [UserController::class, 'show']);
    Route::middleware(['guest', 'throttle:6,1,register'])->post('/register', [UserController::class, 'store']);
    Route::middleware(['guest', 'throttle:6,1,login'])->post('/login', [UserController::class, 'login']);
    Route::middleware(['guest', 'throttle:6,1,social_login'])->post('/login/social', [UserController::class, 'social_login']);
    Route::middleware(['guest', 'throttle:2,1,forgot_password'])->post('/forgot-password', [UserController::class, 'forgot_password']);
    Route::middleware(['guest', 'throttle:2,1,reset_password'])->post('/reset-password', [UserController::class, 'reset_password']);
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::middleware(['throttle:1,1,change-password'])->post('/change-password', [UserController::class, 'change_password']);
        Route::middleware(['throttle:1,1,change-email'])->post('/change-email', [UserController::class, 'change_email']);
        Route::middleware(['throttle:1,1,get_verification_email'])->post('/get-email-verification-link', [UserController::class, 'get_email_verification_link']);
        Route::middleware(['throttle:1,1,change-notifications'])->post('/change-notifications', [UserController::class, 'change_notifications']);
        Route::get('/me', [UserController::class, 'me']);
        Route::post('/verify', [UserController::class, 'verify'])->middleware(CanVerify::class);

        Route::get('/my-polls', [UserController::class, 'my_polls']);
        Route::get('/my-reactions', [UserController::class, 'my_reactions']);
        Route::get('/my-votes', [UserController::class, 'my_votes']);
        Route::get('/my-verifications', [UserController::class, 'my_verifications']);
        Route::get('/my-verifiers', [UserController::class, 'my_verifiers']);

        Route::post('/update/basic-info', [UserController::class, 'update_basic_info']);
        Route::post('/update/social', [UserController::class, 'update_social_links']);
        Route::post('/update/avatar', [UserController::class, 'update_avatar']);
        Route::post('/update/address', [UserController::class, 'update_address']);
        Route::post('/update/census', [UserController::class, 'update_census']);
        Route::post('/logout', [UserController::class, 'logout']);
    });
});

Route::prefix('polls')->group(function () {
    Route::get('/', [PollController::class, 'index']);
    Route::get('/{poll}', [PollController::class, 'show']);
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/', [PollController::class, 'store']);
        Route::post('/status/{poll}', [PollController::class, 'status']);
        Route::post('/vote', [PollController::class, 'vote'])->middleware(UserIsVerified::class);
        Route::post('/react', [PollController::class, 'react'])->middleware(UserIsVerified::class);
    });
});

Route::get('/stats', [StatsController::class, 'index'])->middleware((['throttle:10,1']));

Route::middleware(['guest', 'throttle:6,1,verify_email'])
    ->get('/verify-email', [UserController::class, 'verifyEmail'])
    ->name('verification.verify');
