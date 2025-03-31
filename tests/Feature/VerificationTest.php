<?php

use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\Event;

// ───────────────────────────────────────────────
// Helpers
// ───────────────────────────────────────────────

function authHeader(User $user): array
{
    return [
        'Authorization' => 'Bearer '.explode('|', $user->createToken('test')->plainTextToken)[1],
    ];
}

// ───────────────────────────────────────────────
// Setup
// ───────────────────────────────────────────────

beforeEach(function () {
    $verifiedUser = User::factory()->create([
        'name' => 'Verified',
        'surname' => 'User',
        'email' => 'verified_user@example.com',
        'uuid' => '2d9b73f0-13d1-4d16-9914-8f0f21af6eec',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);

    $unverifiedUser = User::factory()->create([
        'name' => 'Unverified',
        'surname' => 'User',
        'email' => 'unverified_user@example.com',
        'uuid' => '6e0544ad-cd47-480f-9e33-d4fe047b6ab4',
        'verified_at' => null,
        'verification_reason' => null,
    ]);

    test()->verifiedUser = $verifiedUser;
    test()->unverifiedUser = $unverifiedUser;
});

// ───────────────────────────────────────────────
// Service-level Tests
// ───────────────────────────────────────────────

it('allows a first registrant user to verify a new user', function () {
    $result = UserService::canUserAVerifyUserB(test()->verifiedUser, test()->unverifiedUser);

    expect($result)->toBeArray();
    expect($result[0])->toBeTrue();
    expect($result[1])->toBe('');
});

it('prevents unverified users from verifying others', function () {
    $result = UserService::canUserAVerifyUserB(test()->unverifiedUser, test()->verifiedUser);

    expect($result)->toBeArray();
    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('you_are_not_verified');
});

it('prevents users from verifying themselves', function () {
    $result = UserService::canUserAVerifyUserB(test()->verifiedUser, test()->verifiedUser);

    expect($result)->toBeArray();
    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('you_cannot_verify_yourself');
});

// ───────────────────────────────────────────────
// API Tests
// ───────────────────────────────────────────────

it('returns an error if target user has incomplete data', function () {
    $response = $this->postJson(
        route('users.verify'),
        ['uuid' => test()->unverifiedUser->uuid],
        authHeader(test()->verifiedUser)
    );

    $response->assertStatus(403);
    expect($response['messages'])->toContain('target_user_data_not_filled');
});

it('allows a user to verify another user once only', function () {
    // $this->withoutExceptionHandling();
    Event::fake();
    // Create a fresh unverified user with complete profile data
    $unverifiedUser = User::factory()->create([
        'name' => 'Unverified',
        'surname' => 'User',
        'email' => 'unverified_onceonly@example.com',
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
