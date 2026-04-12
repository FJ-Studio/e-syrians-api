<?php

use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    test()->passwordService = resolve(PasswordService::class);
});

// ───────────────────────────────────────────────
// Change Password
// ───────────────────────────────────────────────

it('changes password when current password is correct', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('old_password'),
    ]);

    $result = test()->passwordService->changePassword($user, 'old_password', 'new_password123');

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toBe('password_updated');
    expect(Hash::check('new_password123', $user->fresh()->password))->toBeTrue();
});

it('rejects change when current password is wrong', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('correct_password'),
    ]);

    $result = test()->passwordService->changePassword($user, 'wrong_password', 'new_password123');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('current_password_incorrect');
    expect($result['code'])->toBe(401);
});

// ───────────────────────────────────────────────
// Forgot Password
// ───────────────────────────────────────────────

it('returns success even for non-existent email (privacy)', function (): void {
    $result = test()->passwordService->sendResetLink('nobody@example.com');

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toBe('reset_link_sent');
});
