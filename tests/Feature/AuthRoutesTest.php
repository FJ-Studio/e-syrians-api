<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

// ───────────────────────────────────────────────
// Registration
// ───────────────────────────────────────────────

it('registers a new user via API and returns 201', function (): void {
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

it('returns 422 when registration data is incomplete', function (): void {
    $response = $this->postJson(route('users.register'), [
        'name' => 'Incomplete',
    ]);

    $response->assertStatus(422);
});

it('returns 422 when email is already taken', function (): void {
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

it('logs in with valid credentials and returns token', function (): void {
    User::factory()->create([
        'email' => 'feat_login@gmail.com',
        'password' => Hash::make('secret123'),
    ]);

    $response = $this->postJson('/users/login', [
        'identifier' => 'feat_login@gmail.com',
        'password' => 'secret123',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['user', 'token']]);
});

it('returns 401 for wrong password', function (): void {
    User::factory()->create([
        'email' => 'feat_wrongpw@gmail.com',
        'password' => Hash::make('correct'),
    ]);

    $response = $this->postJson('/users/login', [
        'identifier' => 'feat_wrongpw@gmail.com',
        'password' => 'wrong',
    ]);

    $response->assertStatus(401);
});

it('returns 401 for non-existent user login', function (): void {
    $response = $this->postJson('/users/login', [
        'identifier' => 'ghost@gmail.com',
        'password' => 'anything',
    ]);

    $response->assertStatus(401);
});

it('rejects phone and national ID credentials even when they belong to an account', function (string $identifier): void {
    $user = User::factory()->create([
        'email' => 'email-only@gmail.com',
        'phone' => '+905551234567',
        'national_id' => '12345678901',
        'password' => Hash::make('secret123'),
    ]);

    $this->postJson('/users/login', ['identifier' => $identifier, 'password' => 'secret123'])
        ->assertUnprocessable()
        ->assertJsonStructure(['messages' => ['identifier']]);

    expect($user->tokens()->count())->toBe(0);
})->with(['+905551234567', '12345678901']);

it('requires a bounded email string for password login', function (array $payload): void {
    $this->postJson('/users/login', ['password' => 'secret123', ...$payload])
        ->assertUnprocessable()
        ->assertJsonStructure(['messages' => ['identifier']]);
})->with([
    'missing' => [[]],
    'null' => [['identifier' => null]],
    'empty' => [['identifier' => '']],
    'whitespace' => [['identifier' => '   ']],
    'malformed email' => [['identifier' => 'member@']],
    'array' => [['identifier' => ['member@gmail.com']]],
    'number' => [['identifier' => 12345678901]],
    'too long' => [['identifier' => str_repeat('a', 250).'@gmail.com']],
]);

it('trims the email without altering password whitespace', function (): void {
    User::factory()->create([
        'email' => 'spaced-email@gmail.com',
        'password' => Hash::make(' secret123 '),
    ]);

    $this->postJson('/users/login', [
        'identifier' => ' spaced-email@gmail.com ',
        'password' => ' secret123 ',
    ])->assertOk()->assertJsonStructure(['data' => ['user', 'token']]);
});

it('still requires the second factor after valid email credentials', function (): void {
    $user = User::factory()->create([
        'email' => 'email-two-factor@gmail.com',
        'password' => Hash::make('secret123'),
        'two_factor_enabled' => true,
        'two_factor_confirmed_at' => now(),
    ]);

    $this->postJson('/users/login', [
        'identifier' => 'email-two-factor@gmail.com',
        'password' => 'secret123',
    ])->assertOk()->assertJsonPath('data.requires_2fa', true)
        ->assertJsonStructure(['data' => ['challenge_token', 'expires_at']])
        ->assertJsonMissingPath('data.token');

    expect($user->tokens()->count())->toBe(0);
});

// ───────────────────────────────────────────────
// Logout
// ───────────────────────────────────────────────

it('revokes only the authenticated device session on logout', function (): void {
    $user = User::factory()->create();
    $other = $user->createToken('other-device');
    $thisDevice = $user->createToken('this-device');

    $response = $this->postJson('/users/logout', [], ['Authorization' => 'Bearer '.$thisDevice->plainTextToken]);

    $response->assertOk();
    expect($user->tokens()->pluck('id')->all())->toBe([$other->accessToken->id]);
    auth()->forgetGuards();
    $this->getJson('/users/me', ['Authorization' => 'Bearer '.$thisDevice->plainTextToken])->assertUnauthorized();
    auth()->forgetGuards();
    $this->getJson('/users/me', ['Authorization' => 'Bearer '.$other->plainTextToken])->assertOk();
});

it('rejects a client-supplied Google identity at registration', function (): void {
    $this->postJson(route('users.register'), ['google_id' => 'untrusted-subject'])
        ->assertUnprocessable()->assertJsonStructure(['messages' => ['google_id']]);
});

it('returns 401 when logging out without authentication', function (): void {
    $response = $this->postJson('/users/logout');

    $response->assertStatus(401);
});

// ───────────────────────────────────────────────
// Email Verification Link
// ───────────────────────────────────────────────

it('sends email verification link for unverified user', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $response = $this->postJson('/users/get-email-verification-link', [], authHeader($user));

    $response->assertOk();
});

it('returns 403 when requesting verification link for already verified user', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this->postJson('/users/get-email-verification-link', [], authHeader($user));

    $response->assertStatus(403);
});

// ───────────────────────────────────────────────
// Email Verification
// ───────────────────────────────────────────────

it('rejects email verification without required signature parameter', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $hash = sha1($user->email);

    // Missing the required 'signature' param — should return 422
    $response = $this->getJson("/verify-email?id={$user->id}&hash={$hash}");

    $response->assertStatus(422);
});

it('verifies email via signed URL', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $hash = sha1($user->email);
    // Use $absolute = false to match the service's URL::hasValidSignature(request(), false)
    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => $hash],
        false,
    );

    $response = $this->getJson($url);

    $response->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('rejects email verification with invalid hash via signed URL', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => 'invalidhash'],
        false,
    );

    $response = $this->getJson($url);

    // Service returns 403 for invalid hash
    $response->assertStatus(403);
});

it('rejects email verification for already verified user via signed URL', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $hash = sha1($user->email);
    $url = URL::temporarySignedRoute(
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

it('returns current user profile for authenticated user', function (): void {
    $user = User::factory()->create();

    $response = $this->getJson(route('users.me'), authHeader($user));

    $response->assertOk();
    $response->assertJsonPath('data.uuid', (string) $user->uuid);
});

it('returns 401 when accessing me without authentication', function (): void {
    $response = $this->getJson(route('users.me'));

    $response->assertStatus(401);
});
