<?php

declare(strict_types=1);

use App\Http\Middleware\Recaptcha;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;

/**
 * Wiring-level tests — confirm the `recaptcha` route middleware is actually
 * applied on the endpoints that should be protected. Unit-level behaviour of
 * the middleware lives in tests/Unit/RecaptchaMiddlewareTest.php.
 *
 * TestCase::setUp() disables Recaptcha via `withoutMiddleware()`, which
 * replaces the Recaptcha binding in the container with a pass-through stub.
 * We undo that here by forgetting the instance so the real middleware runs.
 */
beforeEach(function (): void {
    // Re-enable the real Recaptcha middleware (undoes TestCase::setUp's
    // `withoutMiddleware(Recaptcha::class)` which binds a pass-through).
    $this->app->forgetInstance(Recaptcha::class);

    // `/users/forgot-password` has `throttle:2,1,forgot_password` — bypass
    // it so we're only testing recaptcha behaviour.
    $this->withoutMiddleware(ThrottleRequests::class);

    config()->set('services.recaptcha.secret', 'test-secret');
});

/**
 * Helper — hits `/users/forgot-password`, which is declared in routes/api.php as:
 *   Route::middleware(['guest', 'throttle:..', 'recaptcha'])->post('/forgot-password', ...)
 * inside the `Route::prefix('users')` group.
 */
function callForgotPassword(array $payload): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/users/forgot-password', $payload);
}

test('forgot-password rejects requests with no recaptcha_token', function (): void {
    $response = callForgotPassword([
        'email' => 'someone@example.com',
    ]);

    expect($response->status())->toBe(400);
    expect($response->json('messages'))->toContain('recaptcha_token_required');
    // Middleware short-circuits before hitting the HTTP client.
    Http::assertNothingSent();
});

test('forgot-password rejects a token Google marks invalid', function (): void {
    Http::fake([
        'www.google.com/*' => Http::response(['success' => false, 'score' => 0.9], 200),
    ]);

    User::factory()->create(['email' => 'person@example.com']);

    $response = callForgotPassword([
        'email' => 'person@example.com',
        'recaptcha_token' => 'forged-token',
    ]);

    expect($response->status())->toBe(403);
    expect($response->json('messages'))->toContain('recaptcha_verification_failed');
});

test('forgot-password passes the middleware when Google reports a valid high-score token', function (): void {
    Http::fake([
        'www.google.com/*' => Http::response(['success' => true, 'score' => 0.95], 200),
    ]);

    User::factory()->create(['email' => 'person@example.com']);

    $response = callForgotPassword([
        'email' => 'person@example.com',
        'recaptcha_token' => 'real-token',
    ]);

    // Whatever the controller returns next, it must not be a recaptcha rejection.
    expect($response->status())->not->toBe(400);
    expect($response->status())->not->toBe(403);
});
