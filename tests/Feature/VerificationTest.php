<?php

use App\Models\User;
use App\Models\UserVerification;
use Illuminate\Support\Facades\Event;
use App\Contracts\VerificationServiceContract;

// ───────────────────────────────────────────────
// Setup
// ───────────────────────────────────────────────

beforeEach(function (): void {
    test()->verificationService = resolve(VerificationServiceContract::class);

    $verifiedUser = User::factory()->create([
        'name' => 'Verified',
        'surname' => 'User',
        'email' => 'verified_user@gmail.com',
        'uuid' => '2d9b73f0-13d1-4d16-9914-8f0f21af6eec',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);

    $unverifiedUser = User::factory()->create([
        'name' => 'Unverified',
        'surname' => 'User',
        'email' => 'unverified_user@gmail.com',
        'uuid' => '6e0544ad-cd47-480f-9e33-d4fe047b6ab4',
        'verified_at' => null,
        'verification_reason' => null,
    ]);

    test()->verifiedUser = $verifiedUser;
    test()->unverifiedUser = $unverifiedUser;
});

// ───────────────────────────────────────────────
// Service-level Tests (via contract)
// ───────────────────────────────────────────────

it('allows a first registrant user to verify a new user', function (): void {
    $result = test()->verificationService->canUserVerify(test()->verifiedUser, test()->unverifiedUser);

    expect($result)->toBeArray();
    expect($result[0])->toBeTrue();
    expect($result[1])->toBe('');
});

it('prevents unverified users from verifying others', function (): void {
    $result = test()->verificationService->canUserVerify(test()->unverifiedUser, test()->verifiedUser);

    expect($result)->toBeArray();
    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('you_are_not_verified');
});

it('prevents users from verifying themselves', function (): void {
    $result = test()->verificationService->canUserVerify(test()->verifiedUser, test()->verifiedUser);

    expect($result)->toBeArray();
    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('you_cannot_verify_yourself');
});

// ───────────────────────────────────────────────
// API Tests
// ───────────────────────────────────────────────

it('returns an error if target user has incomplete data', function (): void {
    $response = $this->postJson(
        route('users.verify'),
        ['uuid' => test()->unverifiedUser->uuid],
        authHeader(test()->verifiedUser)
    );

    $response->assertStatus(403);
    expect($response['messages'])->toContain('target_user_data_not_filled');
});

it('allows a user to verify another user once only', function (): void {
    // $this->withoutExceptionHandling();
    Event::fake();
    // Create a fresh unverified user with complete profile data
    $unverifiedUser = User::factory()->create([
        'name' => 'Unverified',
        'surname' => 'User',
        'email' => 'unverified_onceonly@gmail.com',
        'uuid' => '26f19555-1111-4aab-a7d2-78ff7e78e890',
        'verified_at' => null,
        'verification_reason' => null,
        'country' => 'US',
        'hometown' => 'damascus',
        'gender' => 'm',
        'birth_date' => '1990-01-01',
    ]);

    // First verification
    $response1 = $this->postJson(
        route('users.verify'),
        ['uuid' => $unverifiedUser->uuid],
        authHeader(test()->verifiedUser)
    );

    $response1->assertStatus(200);

    // Confirm DB change
    $this->assertDatabaseHas('user_verifications', [
        'verifier_id' => test()->verifiedUser->id,
        'user_id' => $unverifiedUser->id,
    ]);

    // Second attempt should fail
    $response2 = $this->postJson(
        route('users.verify'),
        ['uuid' => $unverifiedUser->uuid],
        authHeader(test()->verifiedUser)
    );

    $response2->assertStatus(403);
    expect($response2['messages'])->toContain('you_have_already_verified_this_user');
});

// ───────────────────────────────────────────────
// Cancel verification (P3.3 follow-up)
// ───────────────────────────────────────────────

it('lets the original verifier soft-cancel a verification they sent', function (): void {
    // Set up: verifier vouches for a target, then cancels the row.
    $verification = UserVerification::create([
        'verifier_id' => test()->verifiedUser->id,
        'user_id' => test()->unverifiedUser->id,
    ]);

    $response = $this->postJson(
        route('users.verifications.cancel', ['verification' => $verification->id]),
        [],
        authHeader(test()->verifiedUser)
    );

    $response->assertStatus(200);

    // Row stays in DB (soft-cancel), `cancelled_at` is set, and
    // the payload records the reason. Audit trail preserved.
    $verification->refresh();
    expect($verification->cancelled_at)->not->toBeNull();
    expect($verification->cancelation_payload)->toMatchArray([
        'reason' => 'cancelled_by_verifier',
        'cancelled_by' => 'verifier',
    ]);
});

it('blocks a non-verifier from cancelling someone else\'s verification', function (): void {
    $thirdParty = User::factory()->create([
        'name' => 'Third',
        'surname' => 'Party',
        'email' => 'third@example.com',
        'uuid' => '11111111-2222-3333-4444-555555555555',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);

    $verification = UserVerification::create([
        'verifier_id' => test()->verifiedUser->id,
        'user_id' => test()->unverifiedUser->id,
    ]);

    // Third party (not the original verifier) tries to cancel — 403.
    $response = $this->postJson(
        route('users.verifications.cancel', ['verification' => $verification->id]),
        [],
        authHeader($thirdParty)
    );

    $response->assertStatus(403);
    expect($response['messages'])->toContain('not_authorized_to_cancel_this_verification');

    // Sanity: the row stays uncancelled.
    $verification->refresh();
    expect($verification->cancelled_at)->toBeNull();
});

it('rejects re-cancelling a verification that is already cancelled', function (): void {
    $verification = UserVerification::create([
        'verifier_id' => test()->verifiedUser->id,
        'user_id' => test()->unverifiedUser->id,
        'cancelled_at' => now(),
        'cancelation_payload' => ['reason' => 'cancelled_by_verifier'],
    ]);

    $response = $this->postJson(
        route('users.verifications.cancel', ['verification' => $verification->id]),
        [],
        authHeader(test()->verifiedUser)
    );

    $response->assertStatus(403);
    expect($response['messages'])->toContain('verification_already_cancelled');
});
