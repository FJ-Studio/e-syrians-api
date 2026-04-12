<?php

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    test()->authService = resolve(AuthService::class);
});

// ───────────────────────────────────────────────
// Register
// ───────────────────────────────────────────────

it('registers a new user with valid data', function (): void {
    $user = test()->authService->register([
        'name' => 'John',
        'surname' => 'Doe',
        'email' => 'register-test@example.com',
        'password' => 'password123',
        'national_id' => '999888777'.rand(1, 999),
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'TR',
        'ethnicity' => 'arab',
    ]);

    expect($user)->toBeInstanceOf(User::class);
    expect($user->name)->toBe('John');
    expect($user->surname)->toBe('Doe');
    expect($user->hasRole('citizen'))->toBeTrue();
    $this->assertDatabaseHas('users', ['email' => 'register-test@example.com']);
});

it('converts array fields to comma-separated strings on registration', function (): void {
    $user = test()->authService->register([
        'name' => 'Jane',
        'surname' => 'Doe',
        'email' => 'register-arrays@example.com',
        'password' => 'password123',
        'national_id' => '111222333'.rand(1, 999),
        'gender' => 'f',
        'birth_date' => '1992-05-15',
        'hometown' => 'aleppo',
        'country' => 'US',
        'ethnicity' => 'arab',
        'languages' => ['english', 'arabic'],
        'other_nationalities' => ['TR', 'US'],
    ]);

    expect($user->languages)->toBe('english,arabic');
    expect($user->other_nationalities)->toBe('TR,US');
});

// ───────────────────────────────────────────────
// Credentials Login
// ───────────────────────────────────────────────

it('authenticates a user with valid email credentials', function (): void {
    $user = User::factory()->create([
        'email' => 'login-test@example.com',
        'password' => Hash::make('secret123'),
    ]);

    $result = test()->authService->authenticateViaCredentials('login-test@example.com', 'secret123');

    expect($result)->not->toBeNull();
    expect($result['user']->id)->toBe($user->id);
    expect($result['token'])->toBeString();
});

it('returns null for wrong password', function (): void {
    User::factory()->create([
        'email' => 'wrong-pw@example.com',
        'password' => Hash::make('correct_password'),
    ]);

    $result = test()->authService->authenticateViaCredentials('wrong-pw@example.com', 'wrong_password');

    expect($result)->toBeNull();
});

it('returns null for non-existent user', function (): void {
    $result = test()->authService->authenticateViaCredentials('nonexistent@example.com', 'password');

    expect($result)->toBeNull();
});

// ───────────────────────────────────────────────
// Logout
// ───────────────────────────────────────────────

it('revokes all tokens on logout', function (): void {
    $user = User::factory()->create();
    $user->createToken('test-token');
    $user->createToken('another-token');

    expect($user->tokens()->count())->toBe(2);

    test()->authService->logout($user);

    expect($user->tokens()->count())->toBe(0);
});

// ───────────────────────────────────────────────
// Email Verification
// ───────────────────────────────────────────────

it('rejects verification for already-verified user', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $result = test()->authService->verifyEmail($user->id, sha1($user->email), '');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('user_already_verified');
    expect($result['code'])->toBe(403);
});

it('rejects verification with invalid hash', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $result = test()->authService->verifyEmail($user->id, 'invalid-hash', '');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('invalid_verification_link');
});
