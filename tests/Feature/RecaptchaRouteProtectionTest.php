<?php

declare(strict_types=1);

use App\Models\User;
use App\Http\Middleware\Recaptcha;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Routing\Middleware\ThrottleRequests;

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

    // Enterprise config block — the middleware now calls the
    // Assessments API instead of the legacy siteverify endpoint, so all
    // four fields must be set for the middleware to proceed.
    config()->set('services.recaptcha.project_id', 'test-project');
    config()->set('services.recaptcha.api_key', 'test-api-key');
    config()->set('services.recaptcha.site_key', 'test-site-key');
    config()->set('services.recaptcha.min_score', 0.7);
});

/**
 * Helper — hits `/users/forgot-password`, which is declared in routes/api.php as:
 *   Route::middleware(['guest', 'throttle:..', 'recaptcha'])->post('/forgot-password', ...)
 * inside the `Route::prefix('users')` group.
 */
function callForgotPassword(array $payload): TestResponse
{
    return test()->postJson('/users/forgot-password', $payload);
}

test('forgot-password rejects requests with no recaptcha_token', function (): void {
    $response = callForgotPassword([
        'email' => 'someone@gmail.com',
    ]);

    expect($response->status())->toBe(400);
    expect($response->json('messages'))->toContain('recaptcha_token_required');
    // Middleware short-circuits before hitting the HTTP client.
    Http::assertNothingSent();
});

test('forgot-password rejects a token Google marks invalid', function (): void {
    Http::fake([
        'recaptchaenterprise.googleapis.com/*' => Http::response([
            'tokenProperties' => ['valid' => false, 'invalidReason' => 'MALFORMED'],
            'riskAnalysis' => ['score' => 0.0],
        ], 200),
    ]);

    User::factory()->create(['email' => 'person@gmail.com']);

    $response = callForgotPassword([
        'email' => 'person@gmail.com',
        'recaptcha_token' => 'forged-token',
    ]);

    expect($response->status())->toBe(403);
    expect($response->json('messages'))->toContain('recaptcha_verification_failed');
});

test('forgot-password passes the middleware when Google reports a valid high-score token', function (): void {
    Http::fake([
        'recaptchaenterprise.googleapis.com/*' => Http::response([
            'tokenProperties' => ['valid' => true, 'action' => 'forgot_password'],
            'riskAnalysis' => ['score' => 0.95],
        ], 200),
    ]);

    User::factory()->create(['email' => 'person@gmail.com']);

    $response = callForgotPassword([
        'email' => 'person@gmail.com',
        'recaptcha_token' => 'real-token',
    ]);

    // Whatever the controller returns next, it must not be a recaptcha rejection.
    expect($response->status())->not->toBe(400);
    expect($response->status())->not->toBe(403);
});
