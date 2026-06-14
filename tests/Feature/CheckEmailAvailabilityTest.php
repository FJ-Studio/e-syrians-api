<?php

declare(strict_types=1);

use App\Models\User;
use App\Http\Middleware\Recaptcha;
use Illuminate\Support\Facades\Http;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Tests for `POST /users/check-email-availability` — the pre-registration
 * email-existence probe used by the mobile sign-up wizard on step 1.
 *
 * Pest base disables the recaptcha middleware via withoutMiddleware() in
 * TestCase::setUp, so the happy-path tests below don't need a real
 * token. The recaptcha-gating tests at the bottom re-enable it (mirroring
 * the pattern in tests/Feature/RecaptchaRouteProtectionTest.php).
 */

beforeEach(function (): void {
    // Throttle is on the route at 10/min — bypass for these tests so we
    // can run multiple assertions back-to-back. Throttle wiring itself
    // is exercised in its own test below.
    $this->withoutMiddleware(ThrottleRequests::class);
});

// ───────────────────────────────────────────────
// Happy paths
// ───────────────────────────────────────────────

it('returns available=true for an email that is not registered', function (): void {
    $response = $this->postJson(route('users.check_email_availability'), [
        'email' => 'never_seen_before@gmail.com',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.available', true);
});

it('returns available=false for an email that is already registered', function (): void {
    User::factory()->create(['email' => 'already_taken@gmail.com']);

    $response = $this->postJson(route('users.check_email_availability'), [
        'email' => 'already_taken@gmail.com',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.available', false);
});

it('treats the email comparison as case-insensitive', function (): void {
    User::factory()->create(['email' => 'mixed.case@gmail.com']);

    $response = $this->postJson(route('users.check_email_availability'), [
        'email' => 'Mixed.Case@gmail.COM',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.available', false);
});

it('trims whitespace around the email before comparing', function (): void {
    User::factory()->create(['email' => 'spaces@gmail.com']);

    $response = $this->postJson(route('users.check_email_availability'), [
        'email' => '  spaces@gmail.com  ',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.available', false);
});

// ───────────────────────────────────────────────
// Validation
// ───────────────────────────────────────────────

it('returns 422 when email is missing', function (): void {
    $response = $this->postJson(route('users.check_email_availability'), []);

    $response->assertStatus(422);
});

it('returns 422 when email is not a valid format', function (): void {
    $response = $this->postJson(route('users.check_email_availability'), [
        'email' => 'not-an-email',
    ]);

    $response->assertStatus(422);
});

// ───────────────────────────────────────────────
// Recaptcha gate
// ───────────────────────────────────────────────

it('is gated by the recaptcha middleware', function (): void {
    // Restore the real recaptcha middleware so this test exercises it.
    $this->app->forgetInstance(Recaptcha::class);
    config()->set('services.recaptcha.secret', 'test-secret');

    $response = $this->postJson(route('users.check_email_availability'), [
        'email' => 'test@gmail.com',
    ]);

    expect($response->status())->toBe(400);
    expect($response->json('messages'))->toContain('recaptcha_token_required');
    Http::assertNothingSent();
});

it('passes when a valid recaptcha token is supplied', function (): void {
    $this->app->forgetInstance(Recaptcha::class);
    config()->set('services.recaptcha.secret', 'test-secret');

    Http::fake([
        'www.google.com/*' => Http::response(['success' => true, 'score' => 0.95], 200),
    ]);

    $response = $this->postJson(route('users.check_email_availability'), [
        'email' => 'fresh@gmail.com',
        'recaptcha_token' => 'good-token',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.available', true);
});

// ───────────────────────────────────────────────
// Throttling
// ───────────────────────────────────────────────

it('is rate-limited at 10 requests per minute per IP', function (): void {
    // Restore the throttle this group disabled.
    $this->app->forgetInstance(ThrottleRequests::class);

    // First 10 requests pass; the 11th is 429.
    for ($i = 0; $i < 10; $i++) {
        $this->postJson(route('users.check_email_availability'), [
            'email' => "throttle{$i}@gmail.com",
        ])->assertOk();
    }

    $response = $this->postJson(route('users.check_email_availability'), [
        'email' => 'over_the_limit@gmail.com',
    ]);

    $response->assertStatus(429);
});
