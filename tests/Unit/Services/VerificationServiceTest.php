<?php

use App\Models\User;
use App\Services\VerificationService;

beforeEach(function (): void {
    test()->verificationService = resolve(VerificationService::class);

    test()->verifiedUser = User::factory()->create([
        'name' => 'Verified',
        'surname' => 'User',
        'email' => 'vs_verified@example.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);

    test()->unverifiedUser = User::factory()->create([
        'name' => 'Unverified',
        'surname' => 'User',
        'email' => 'vs_unverified@example.com',
        'verified_at' => null,
    ]);

    test()->bannedUser = User::factory()->create([
        'name' => 'Banned',
        'surname' => 'User',
        'email' => 'vs_banned@example.com',
        'marked_as_fake_at' => now(),
    ]);
});

// ───────────────────────────────────────────────
// canUserVerify()
// ───────────────────────────────────────────────

it('allows a verified first registrant to verify another user', function (): void {
    $result = test()->verificationService->canUserVerify(test()->verifiedUser, test()->unverifiedUser);

    expect($result[0])->toBeTrue();
    expect($result[1])->toBe('');
});

it('prevents unverified user from verifying', function (): void {
    $result = test()->verificationService->canUserVerify(test()->unverifiedUser, test()->verifiedUser);

    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('you_are_not_verified');
});

it('prevents self-verification', function (): void {
    $result = test()->verificationService->canUserVerify(test()->verifiedUser, test()->verifiedUser);

    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('you_cannot_verify_yourself');
});

it('prevents verifying a banned user', function (): void {
    $result = test()->verificationService->canUserVerify(test()->verifiedUser, test()->bannedUser);

    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('user_is_banned');
});

it('resolves users by UUID string', function (): void {
    $result = test()->verificationService->canUserVerify(
        test()->verifiedUser->uuid,
        test()->unverifiedUser->uuid,
    );

    expect($result[0])->toBeTrue();
});

it('resolves users by integer ID', function (): void {
    $result = test()->verificationService->canUserVerify(
        test()->verifiedUser->id,
        test()->unverifiedUser->id,
    );

    expect($result[0])->toBeTrue();
});

it('returns error for non-existent verifier', function (): void {
    $result = test()->verificationService->canUserVerify(999999, test()->unverifiedUser);

    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('user_not_found');
});

it('returns error for non-existent target', function (): void {
    $result = test()->verificationService->canUserVerify(test()->verifiedUser, 999999);

    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('target_user_not_found');
});

// ───────────────────────────────────────────────
// verifyUser()
// ───────────────────────────────────────────────

it('throws when target user has incomplete data', function (): void {
    expect(fn () => test()->verificationService->verifyUser(
        test()->verifiedUser,
        test()->unverifiedUser->uuid,
        '127.0.0.1',
        'TestAgent',
    ))->toThrow(DomainException::class, 'target_user_data_not_filled');
});

it('creates a verification record for complete target user', function (): void {
    $target = User::factory()->create([
        'name' => 'Complete',
        'surname' => 'Target',
        'email' => 'vs_complete@example.com',
        'verified_at' => null,
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'US',
    ]);

    test()->verificationService->verifyUser(
        test()->verifiedUser,
        $target->uuid,
        '127.0.0.1',
        'TestAgent',
    );

    $this->assertDatabaseHas('user_verifications', [
        'verifier_id' => test()->verifiedUser->id,
        'user_id' => $target->id,
    ]);
});

// ───────────────────────────────────────────────
// Circular Verification
// ───────────────────────────────────────────────

it('prevents circular verification', function (): void {
    // Create 2 verified users
    $userA = User::factory()->create([
        'email' => 'vs_circular_a@example.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'US',
    ]);
    $userB = User::factory()->create([
        'email' => 'vs_circular_b@example.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'f',
        'birth_date' => '1992-05-15',
        'hometown' => 'aleppo',
        'country' => 'TR',
    ]);

    // A verifies B
    test()->verificationService->verifyUser($userA, $userB->uuid, '127.0.0.1', 'Test');

    // B tries to verify A — should be blocked
    $result = test()->verificationService->canUserVerify($userB, $userA);

    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('circular_verification_not_allowed');
});
