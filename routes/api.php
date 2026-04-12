<?php

use App\Http\Middleware\CanVerify;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\UserIsVerified;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PollController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\UserPollController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\ViolationController;
use App\Http\Controllers\RecoveryCodeController;
use App\Http\Controllers\VerificationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/ping', function () {
    return response()->json(['message' => 'pong']);
})->name('ping');

/*
|--------------------------------------------------------------------------
| Auth Routes (guest only)
|--------------------------------------------------------------------------
*/
Route::prefix('users')->group(function (): void {
    Route::middleware(['guest', 'throttle:6,1,register'])->post('/register', [AuthController::class, 'register'])->name('users.register');
    Route::middleware(['guest', 'throttle:6,1,login'])->post('/login', [AuthController::class, 'login']);
    Route::middleware(['guest', 'throttle:6,1,social_login'])->post('/login/social', [AuthController::class, 'socialLogin']);
    Route::middleware(['guest', 'throttle:2,1,forgot_password'])->post('/forgot-password', [PasswordController::class, 'forgot']);
    Route::middleware(['guest', 'throttle:2,1,reset_password'])->post('/reset-password', [PasswordController::class, 'reset']);

    // 2FA verification during login (no auth required, uses challenge token)
    Route::middleware(['guest', 'throttle:6,1'])->post('/2fa/verify', [TwoFactorController::class, 'verify']);
});

/*
|--------------------------------------------------------------------------
| Public User Routes
|--------------------------------------------------------------------------
*/
Route::prefix('users')->group(function (): void {
    Route::get('/first', [UserController::class, 'first']);
    Route::get('/verify/{user:uuid}', [UserController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Authenticated User Routes
|--------------------------------------------------------------------------
*/
Route::prefix('users')->middleware(['auth:sanctum'])->group(function (): void {
    // Current user
    Route::get('/me', [UserController::class, 'me'])->name('users.me');
    Route::post('/logout', [AuthController::class, 'logout']);

    // Password management
    Route::middleware(['throttle:1,1,change-password'])->post('/change-password', [PasswordController::class, 'change']);

    // Email & verification
    Route::middleware(['throttle:1,1,change-email'])->post('/change-email', [ProfileController::class, 'changeEmail']);
    Route::middleware(['throttle:1,1,get_verification_email'])->post('/get-email-verification-link', [AuthController::class, 'getEmailVerificationLink']);

    // Notification preferences
    Route::middleware(['throttle:1,1,change-notifications'])->post('/change-notifications', [ProfileController::class, 'changeNotifications']);

    // Two-Factor Authentication
    Route::prefix('2fa')->group(function (): void {
        Route::get('/status', [TwoFactorController::class, 'status']);
        Route::post('/setup', [TwoFactorController::class, 'setup']);
        Route::post('/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/disable', [TwoFactorController::class, 'disable']);
    });

    // Recovery codes
    Route::get('/recovery-codes', [RecoveryCodeController::class, 'index']);
    Route::post('/recovery-codes/regenerate', [RecoveryCodeController::class, 'regenerate']);

    // User verification
    Route::post('/verify', [VerificationController::class, 'verify'])->middleware(CanVerify::class)->name('users.verify');

    // User's polls, votes, reactions
    Route::get('/my-polls', [UserPollController::class, 'myPolls']);
    Route::get('/my-reactions', [UserPollController::class, 'myReactions']);
    Route::get('/my-votes', [UserPollController::class, 'myVotes']);

    // User's verifications
    Route::get('/my-verifications', [VerificationController::class, 'myVerifications']);
    Route::get('/my-verifiers', [VerificationController::class, 'myVerifiers']);

    // Profile updates
    Route::post('/update/basic-info', [ProfileController::class, 'updateBasicInfo'])->name('users.update.basic-info');
    Route::post('/update/social', [ProfileController::class, 'updateSocialLinks'])->name('users.update.social');
    Route::post('/update/avatar', [ProfileController::class, 'updateAvatar'])->name('users.update.avatar');
    Route::post('/update/address', [ProfileController::class, 'updateAddress'])->name('users.update.address');
    Route::post('/update/language', [ProfileController::class, 'updateLanguage'])->middleware(['throttle:4,1,change-language']);
    Route::post('/update/census', [ProfileController::class, 'updateCensus'])->name('users.update.census');
});

/*
|--------------------------------------------------------------------------
| Poll Routes
|--------------------------------------------------------------------------
*/
Route::prefix('polls')->group(function (): void {
    Route::get('/', [PollController::class, 'index']);
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/option-voters', [PollController::class, 'optionVoters']);
        Route::post('/', [PollController::class, 'store']);
        Route::post('/status/{poll}', [PollController::class, 'status']);
        Route::post('/vote', [PollController::class, 'vote'])->middleware(UserIsVerified::class);
        Route::post('/react', [PollController::class, 'react'])->middleware(UserIsVerified::class);
    });
    Route::get('/{poll}', [PollController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Violation Routes
|--------------------------------------------------------------------------
*/
Route::prefix('violations')->group(function (): void {
    Route::get('/', [ViolationController::class, 'index']);
    Route::get('/{violation}', [ViolationController::class, 'show']);
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::post('/', [ViolationController::class, 'store']);
        Route::post('/react', [ViolationController::class, 'react'])->middleware(UserIsVerified::class);
        Route::post('/attachments', [ViolationController::class, 'attachments']);
    });
});

/*
|--------------------------------------------------------------------------
| Stats & Email Verification
|--------------------------------------------------------------------------
*/
Route::get('/stats', [StatsController::class, 'index'])->middleware((['throttle:10,1']));

Route::middleware(['guest', 'throttle:6,1,verify_email'])
    ->get('/verify-email', [AuthController::class, 'verifyEmail'])
    ->name('verification.verify');
