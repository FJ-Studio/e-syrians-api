<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

// ───────────────────────────────────────────────
// Registration
// ───────────────────────────────────────────────

it('registers a new user via API and returns 201', function () {
    $response = $this->postJson(route('users.register'), [
        'name' => 'Feature',
        'surname' => 'Test',
        'email' => 'feat_register@gmail.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'national_id' => '998877660' . rand(1, 999),
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'TR',
        'ethnicity' => 'arab',
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('users', ['email' => 'feat_register@gmail.com']);
});

it('returns 422 when registration data is incomplete', function () {
    $response = $this->postJson(route('users.register'), [
        'name' => 'Incomplete',
    ]);

    $response->assertStatus(422);
});

it('returns 422 when email is already taken', function () {
    User::factory()->create(['email' => 'dup_email@gmail.com']);

    $response = $this->postJson(route('users.register'), [
        'name' => 'Duplicate',
        'surname' => 'Email',
        'email' => 'dup_email@gmail.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'national_id' => '112233440' . rand(1, 999),
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'TR',
        'ethnicity' => 'arab',
    ]);

    $response->assertStatus(422);
});

// ───────────────────────────────────────────────
// Login
// ───────────────────────────────────────────────

it('logs in with valid credentials and returns token', function () {
    User::factory()->create([
        'email' => 'feat_login@example.com',
        'password' => Hash::make('secret123'),
    ]);

    $response = $this->postJson('/users/login', [
        'identifier' => 'feat_login@example.com',
        'password' => 'secret123',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['user', 'token']]);
});

it('returns 401 for wrong password', function () {
    User::factory()->create([
        'email' => 'feat_wrongpw@example.com',
        'password' => Hash::make('correct'),
    ]);

    $response = $this->postJson('/users/login', [
        'identifier' => 'feat_wrongpw@example.com',
        'password' => 'wrong',
    ]);

    $response->assertStatus(401);
});

it('returns 401 for non-existent user login', function () {
    $response = $this->postJson('/users/login', [
        'identifier' => 'ghost@example.com',
        'password' => 'anything',
    ]);

    $response->assertStatus(401);
});

// ───────────────────────────────────────────────
// Logout
// ───────────────────────────────────────────────

it('logs out authenticated user and revokes tokens', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/users/logout', [], authHeader($user));

    $response->assertOk();
    expect($user->tokens()->count())->toBe(0);
});

it('returns 401 when logging out without authentication', function () {
    $response = $this->postJson('/users/logout');

    $response->assertStatus(401);
});

// ───────────────────────────────────────────────
// Email Verification Link
// ───────────────────────────────────────────────

it('sends email verification link for unverified user', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $response = $this->postJson('/users/get-email-verification-link', [], authHeader($user));

    $response->assertOk();
});

it('returns 403 when requesting verification link for already verified user', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this->postJson('/users/get-email-verification-link', [], authHeader($user));

    $response->assertStatus(403);
});

// ───────────────────────────────────────────────
// Email Verification
// ───────────────────────────────────────────────

it('rejects email verification without required signature parameter', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $hash = sha1($user->email);

    // Missing the required 'signature' param — should return 422
    $response = $this->getJson("/verify-email?id={$user->id}&hash={$hash}");

    $response->assertStatus(422);
});

it('verifies email via signed URL', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $hash = sha1($user->email);
    // Use $absolute = false to match the service's URL::hasValidSignature(request(), false)
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => $hash],
        false,
    );

    $response = $this->getJson($url);

    $response->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('rejects email verification with invalid hash via signed URL', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => 'invalidhash'],
        false,
    );

    $response = $this->getJson($url);

    // Service returns 403 for invalid hash
    $response->assertStatus(403);
});

it('rejects email verification for already verified user via signed URL', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $hash = sha1($user->email);
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => $hash],
        false,
    );

    $response = $this->getJson($url);

    $response->assertStatus(403);
});

// ───────────────────────────────────────────────
// Me (current user)
// ───────────────────────────────────────────────

it('returns current user profile for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->getJson(route('users.me'), authHeader($user));

    $response->assertOk();
    $response->assertJsonPath('data.uuid', (string) $user->uuid);
});

it('returns 401 when accessing me without authentication', function () {
    $response = $this->getJson(route('users.me'));

    $response->assertStatus(401);
});
