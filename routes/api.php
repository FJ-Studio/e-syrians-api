<?php

use App\Http\Middleware\CanVerify;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\UserIsVerified;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PollController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\UserPollController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RecoveryCodeController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\FeatureRequestController;
use App\Http\Controllers\SuspiciousActivityController;

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
    Route::middleware(['guest', 'throttle:6,1,register', 'recaptcha'])->post('/register', [AuthController::class, 'register'])->name('users.register');
    // Pre-registration email-availability probe used by the mobile
    // sign-up wizard (step 1 → Continue). reCAPTCHA-gated so the route
    // can't be abused as an "is this email registered?" oracle; 10/min
    // per-IP throttle is loose enough for honest typos but tight
    // enough that scanning a list is impractical.
    Route::middleware(['guest', 'throttle:10,1,email_check', 'recaptcha'])->post('/check-email-availability', [AuthController::class, 'checkEmailAvailability'])->name('users.check_email_availability');
    Route::middleware(['guest', 'throttle:6,1,login'])->post('/login', [AuthController::class, 'login']);
    Route::middleware(['guest', 'throttle:6,1,social_login'])->post('/login/social', [AuthController::class, 'socialLogin']);
    Route::middleware(['guest', 'throttle:2,1,forgot_password', 'recaptcha'])->post('/forgot-password', [PasswordController::class, 'forgot']);
    Route::middleware(['guest', 'throttle:2,1,reset_password', 'recaptcha'])->post('/reset-password', [PasswordController::class, 'reset']);

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
    Route::middleware(['throttle:3,1,change-password', 'recaptcha'])->post('/change-password', [PasswordController::class, 'change']);
    Route::middleware(['throttle:3,1,send-setup-otp', 'recaptcha'])->post('/password/send-otp', [PasswordController::class, 'sendSetupOtp']);
    Route::middleware(['throttle:3,1,set-password', 'recaptcha'])->post('/password/set', [PasswordController::class, 'setPassword']);

    // Email & verification
    Route::middleware(['throttle:1,1,change-email', 'recaptcha'])->post('/change-email', [ProfileController::class, 'changeEmail']);
    Route::middleware(['throttle:1,1,get-verification-email', 'recaptcha'])->post('/get-email-verification-link', [AuthController::class, 'getEmailVerificationLink']);

    // Notification preferences
    Route::middleware(['throttle:1,1,change-notifications', 'recaptcha'])->post('/change-notifications', [ProfileController::class, 'changeNotifications']);

    // Notifications
    Route::prefix('notifications')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::post('/{id}/mark-read', [NotificationController::class, 'markAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });

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
    Route::post('/verify', [VerificationController::class, 'verify'])->middleware([CanVerify::class, 'recaptcha'])->name('users.verify');

    // User's polls, votes, reactions
    Route::get('/my-polls', [UserPollController::class, 'myPolls']);
    Route::get('/my-reactions', [UserPollController::class, 'myReactions']);
    Route::get('/my-votes', [UserPollController::class, 'myVotes']);

    // User's verifications
    Route::get('/my-verifications', [VerificationController::class, 'myVerifications']);
    Route::get('/my-verifiers', [VerificationController::class, 'myVerifiers']);
    // Cancel a verification the auth user previously sent. POST
    // (not DELETE) because the row stays in the DB — we soft-cancel
    // via `cancelled_at` + payload to preserve the audit trail.
    Route::post('/verifications/{verification}/cancel', [VerificationController::class, 'cancel'])
        ->name('users.verifications.cancel');

    // Push-notification device registration (OneSignal).
    //
    // POST   /users/devices             — register or re-register
    // DELETE /users/devices/{device}    — unregister (route binding
    //                                     resolves by subscription_id,
    //                                     see Device::getRouteKeyName)
    //
    // Throttled per-user (30 writes/min) to absorb a buggy SDK build
    // that re-registers on every notification — generous enough that
    // legitimate clients (one register at login, occasional re-register
    // on subscription change) never hit the limit.
    Route::middleware(['throttle:30,1,devices'])->group(function (): void {
        Route::post('/devices', [DeviceController::class, 'store'])->name('users.devices.store');
        // Raw string param (NOT implicit route-model binding). We
        // handle the "device not found" case ourselves and return
        // 204 either way — the spec says DELETE is idempotent and
        // must not leak whether a given subscription_id exists.
        // Implicit binding would 404 before the controller runs,
        // which contradicts that contract.
        Route::delete('/devices/{subscription_id}', [DeviceController::class, 'destroy'])->name('users.devices.destroy');
    });

    // Profile updates — all protected by reCAPTCHA except language (silent
    // preference toggle with no user-visible form).
    Route::middleware(['recaptcha'])->group(function (): void {
        Route::post('/update/basic-info', [ProfileController::class, 'updateBasicInfo'])->name('users.update.basic-info');
        Route::post('/update/social', [ProfileController::class, 'updateSocialLinks'])->name('users.update.social');
        Route::post('/update/avatar', [ProfileController::class, 'updateAvatar'])->name('users.update.avatar');
        Route::post('/update/address', [ProfileController::class, 'updateAddress'])->name('users.update.address');
        Route::post('/update/census', [ProfileController::class, 'updateCensus'])->name('users.update.census');
    });
    Route::post('/update/language', [ProfileController::class, 'updateLanguage'])->middleware(['throttle:4,1,change-language']);
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
        Route::post('/', [PollController::class, 'store'])->middleware('recaptcha');
        // Creator-only edit payload. Mirrors `show()` but exposes
        // the full audience block (incl. allowed_voters) that the
        // public show endpoint intentionally suppresses — the
        // edit form would otherwise wipe the allowlist on save.
        // Must be declared BEFORE `/{poll}` so the literal segment
        // doesn't get swallowed by the dynamic show route. Sits
        // under auth:sanctum; the controller checks ownership.
        Route::get('/{poll}/edit', [PollController::class, 'editPayload']);
        // Edit gate is in UpdatePollRequest::authorize (ownership +
        // zero-votes). Must be PATCH not POST so it doesn't collide
        // with the public `/{poll}` show route below.
        Route::patch('/{poll}', [PollController::class, 'update'])->middleware('recaptcha');
        // Uses `{pollId}` (not `{poll}`) on purpose: the
        // AppServiceProvider's `Route::bind('poll', …)` resolves
        // the parameter to a Poll model without the `public_polls`
        // global scope, but it does NOT apply `withTrashed()`.
        // Status toggles a soft-deleted poll (Activate flips it
        // back), so the controller has to do its own withTrashed
        // lookup. A different parameter name keeps the binding
        // out of the way and lets `int $pollId` resolve naturally.
        Route::post('/status/{pollId}', [PollController::class, 'status'])->middleware('recaptcha');
        Route::post('/vote', [PollController::class, 'vote'])->middleware([UserIsVerified::class, 'recaptcha']);
        Route::post('/react', [PollController::class, 'react'])->middleware([UserIsVerified::class, 'recaptcha']);
    });
    Route::get('/audience', [PollController::class, 'audience']);
    Route::get('/{poll}', [PollController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Feature Request Routes
|--------------------------------------------------------------------------
*/
Route::prefix('feature-requests')->group(function (): void {
    Route::get('/', [FeatureRequestController::class, 'index']);
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::post('/', [FeatureRequestController::class, 'store'])
            ->middleware([UserIsVerified::class, 'throttle:5,10,feature_request_store', 'recaptcha']);
        Route::post('/vote', [FeatureRequestController::class, 'vote'])
            ->middleware([UserIsVerified::class, 'throttle:30,1,feature_request_vote', 'recaptcha']);
        Route::delete('/vote/{id}', [FeatureRequestController::class, 'unvote'])
            ->middleware([UserIsVerified::class, 'throttle:30,1,feature_request_vote', 'recaptcha'])
            ->whereNumber('id');

        // Admin-only moderation + timeline transitions. Gated by Spatie's
        // role middleware (registered automatically by spatie/laravel-permission).
        Route::middleware(['role:admin'])->group(function (): void {
            Route::post('/{id}/timeline', [FeatureRequestController::class, 'timeline'])
                ->whereNumber('id');
            Route::delete('/{id}', [FeatureRequestController::class, 'destroy'])
                ->whereNumber('id');
            Route::post('/{id}/restore', [FeatureRequestController::class, 'restore'])
                ->whereNumber('id');
        });
    });
    Route::get('/{featureRequest}', [FeatureRequestController::class, 'show'])
        ->whereNumber('featureRequest');
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

/*
|--------------------------------------------------------------------------
| Internal API Routes (Cloud Function webhooks)
|--------------------------------------------------------------------------
*/
Route::prefix('internal')->middleware(['internal-api'])->group(function (): void {
    Route::post('/suspicious-activity', [SuspiciousActivityController::class, 'store']);
});
