<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// ───────────────────────────────────────────────
// Change Password
// ───────────────────────────────────────────────

it('changes password with correct current password', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('old_password'),
    ]);

    $response = $this->postJson('/users/change-password', [
        'current_password' => 'old_password',
        'new_password' => 'new_password123',
        'new_password_confirmation' => 'new_password123',
    ], authHeader($user));

    $response->assertOk();
    expect(Hash::check('new_password123', $user->fresh()->password))->toBeTrue();
});

it('rejects password change with wrong current password', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('correct_password'),
    ]);

    $response = $this->postJson('/users/change-password', [
        'current_password' => 'wrong_password',
        'new_password' => 'new_password123',
        'new_password_confirmation' => 'new_password123',
    ], authHeader($user));

    $response->assertStatus(401);
});

it('returns 422 when new password is missing confirmation', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('old_password'),
    ]);

    $response = $this->postJson('/users/change-password', [
        'current_password' => 'old_password',
        'new_password' => 'new_password123',
    ], authHeader($user));

    $response->assertStatus(422);
});

it('returns 422 when new password is same as current', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('same_password'),
    ]);

    $response = $this->postJson('/users/change-password', [
        'current_password' => 'same_password',
        'new_password' => 'same_password',
        'new_password_confirmation' => 'same_password',
    ], authHeader($user));

    $response->assertStatus(422);
});

it('returns 401 when changing password without authentication', function (): void {
    $response = $this->postJson('/users/change-password', [
        'current_password' => 'anything',
        'new_password' => 'new_password123',
        'new_password_confirmation' => 'new_password123',
    ]);

    $response->assertStatus(401);
});

// ───────────────────────────────────────────────
// Forgot Password
// ───────────────────────────────────────────────

it('returns success for forgot password with existing email', function (): void {
    User::factory()->create(['email' => 'feat_forgot@example.com']);

    $response = $this->postJson('/users/forgot-password', [
        'email' => 'feat_forgot@example.com',
    ]);

    $response->assertOk();
});

it('returns success for forgot password with non-existent email (privacy)', function (): void {
    $response = $this->postJson('/users/forgot-password', [
        'email' => 'nonexistent@example.com',
    ]);

    $response->assertOk();
});

it('returns 422 for forgot password with invalid email format', function (): void {
    $response = $this->postJson('/users/forgot-password', [
        'email' => 'not-an-email',
    ]);

    $response->assertStatus(422);
});

// ───────────────────────────────────────────────
// Reset Password
// ───────────────────────────────────────────────

it('returns 422 for reset password with missing fields', function (): void {
    $response = $this->postJson('/users/reset-password', []);

    $response->assertStatus(422);
});

it('returns 422 for reset password without confirmation', function (): void {
    $response = $this->postJson('/users/reset-password', [
        'token' => 'some-token',
        'email' => 'test@example.com',
        'password' => 'new_password123',
    ]);

    $response->assertStatus(422);
});
