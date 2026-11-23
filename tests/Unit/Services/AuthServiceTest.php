<?php

use App\Models\User;
use App\Services\AuthService;
use Laravel\Sanctum\TransientToken;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function (): void {
    test()->authService = resolve(AuthService::class);
    config(['services.google.client_id' => 'e-syrians-test-client']);
});

function mockGoogleIdentity(string $email, string $subject, ?string $name, array $claims = [], bool $signed = true): string
{
    $token = $signed ? str_repeat('a', 105).'.b.c' : 'test-access-token';
    $verifiedClaims = ['email_verified' => true];
    if ($signed) {
        $verifiedClaims += ['aud' => 'e-syrians-test-client', 'iss' => 'https://accounts.google.com', 'exp' => time() + 600];
    }
    $socialUser = (new Laravel\Socialite\Two\User())->setRaw([...$verifiedClaims, ...$claims])->map([
        'id' => $subject, 'name' => $name, 'email' => $email,
    ]);
    Socialite::shouldReceive('driver->userFromToken')->with($token)->andReturn($socialUser);

    return $token;
}

it('never stores a Google login email as the public name', function (?string $name): void {
    $email = 'private-login@gmail.com';
    $token = mockGoogleIdentity($email, 'google-user-without-public-name', $name);

    $result = test()->authService->authenticateViaSocialProvider('google', $token);

    expect($result)->not->toBeNull()
        ->and($result['user']->getRawOriginal('name'))->toBe(User::DEFAULT_DISPLAY_NAME)
        ->and($result['user']->email)->toBe($email);
})->with([null, 'private-login@gmail.com']);

it('requires verified Google claims and rejects conflicts with an existing account', function (array $attributes, array $claims): void {
    $email = 'linking-test@gmail.com';
    $user = User::factory()->create(['email' => $email, ...$attributes]);
    $token = mockGoogleIdentity($email, 'verified-google-subject', 'Google Member', $claims);

    expect(test()->authService->authenticateViaSocialProvider('google', $token))->toBeNull()
        ->and($user->tokens()->count())->toBe(0)
        ->and($user->fresh()->google_id)->toBe($user->google_id);
})->with([
    'unverified local email' => [['email_verified_at' => null], ['email_verified' => true]],
    'unverified provider email' => [[], ['email_verified' => false]],
    'different linked subject' => [['google_id' => 'other-subject'], ['email_verified' => true]],
    'wrong audience' => [[], ['aud' => 'different-client']],
    'expired token' => [[], ['exp' => 1]],
]);

it('links a verified matching Google identity and keeps repeat sign-in on the same account', function (): void {
    $email = 'linking-verified@gmail.com';
    $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
    $token = mockGoogleIdentity($email, 'verified-google-subject', 'Google Member');
    foreach (range(1, 2) as $_) {
        $result = test()->authService->authenticateViaSocialProvider('google', $token);
        expect($result['user']->id)->toBe($user->id);
    }
    expect($user->fresh()->google_id)->toBe('verified-google-subject');
});

it('limits older Google access tokens to accounts already linked to the verified identity', function (): void {
    $email = 'existing-login@gmail.com';
    $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
    $token = mockGoogleIdentity($email, 'legacy-subject', 'Google Member', signed: false);

    expect(test()->authService->authenticateViaSocialProvider('google', $token))->toBeNull();
    $user->update(['google_id' => 'legacy-subject']);
    expect(test()->authService->authenticateViaSocialProvider('google', $token)['user']->id)->toBe($user->id);
});

it('does not accept a legacy subject without its matching verified email', function (): void {
    $legacy = User::factory()->create(['google_id' => 'legacy-subject']);
    $token = mockGoogleIdentity('different-owner@gmail.com', 'legacy-subject', 'Google Member');

    expect(test()->authService->authenticateViaSocialProvider('google', $token))->toBeNull()
        ->and($legacy->tokens()->count())->toBe(0);
});

it('masks an existing email fallback in both public profile and partial model reads', function (): void {
    $email = 'legacy-login@example.com';
    $user = User::factory()->create(['name' => $email, 'surname' => '', 'email' => $email]);

    $this->getJson('/users/verify/'.$user->uuid)->assertOk()
        ->assertJsonPath('data.name', User::DEFAULT_DISPLAY_NAME)
        ->assertJsonMissingPath('data.email');

    expect(User::query()->select('id', 'name', 'surname')->findOrFail($user->id)->name)
        ->toBe(User::DEFAULT_DISPLAY_NAME);
});

// ───────────────────────────────────────────────
// Register
// ───────────────────────────────────────────────

it('registers a new user with valid data', function (): void {
    $user = test()->authService->register([
        'name' => 'John',
        'surname' => 'Doe',
        'email' => 'register-test@gmail.com',
        'password' => 'password123',
        'national_id' => '999888777' . rand(1, 999),
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
    $this->assertDatabaseHas('users', ['email' => 'register-test@gmail.com']);
});

it('converts array fields to comma-separated strings on registration', function (): void {
    $user = test()->authService->register([
        'name' => 'Jane',
        'surname' => 'Doe',
        'email' => 'register-arrays@gmail.com',
        'password' => 'password123',
        'national_id' => '111222333' . rand(1, 999),
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
        'email' => 'login-test@gmail.com',
        'password' => Hash::make('secret123'),
    ]);

    $result = test()->authService->authenticateViaCredentials('login-test@gmail.com', 'secret123');

    expect($result)->not->toBeNull();
    expect($result['user']->id)->toBe($user->id);
    expect($result['token'])->toBeString();
});

it('returns null for wrong password', function (): void {
    User::factory()->create([
        'email' => 'wrong-pw@gmail.com',
        'password' => Hash::make('correct_password'),
    ]);

    $result = test()->authService->authenticateViaCredentials('wrong-pw@gmail.com', 'wrong_password');

    expect($result)->toBeNull();
});

it('returns null for non-existent user', function (): void {
    $result = test()->authService->authenticateViaCredentials('nonexistent@gmail.com', 'password');

    expect($result)->toBeNull();
});

it('never authenticates against phone or national ID hashes', function (string $field, string $value): void {
    $user = User::factory()->create([
        'email' => 'only-this-email@gmail.com',
        $field => $value,
        'password' => Hash::make('secret123'),
    ]);

    expect(test()->authService->authenticateViaCredentials($value, 'secret123'))->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
})->with([
    'phone' => ['phone', '+905551234567'],
    'national ID' => ['national_id', '12345678901'],
    'email-shaped phone value' => ['phone', 'not-a-login@gmail.com'],
    'email-shaped national ID value' => ['national_id', 'not-a-login@gmail.com'],
]);

// ───────────────────────────────────────────────
// Logout
// ───────────────────────────────────────────────

it('revokes only the supplied personal access token', function (): void {
    $user = User::factory()->create();
    $current = $user->createToken('this-device');
    $other = $user->createToken('another-device');

    test()->authService->logout($current->accessToken);

    expect($user->tokens()->pluck('id')->all())->toBe([$other->accessToken->id]);
});

it('leaves bearer sessions alone without a current bearer token', function (): void {
    $user = User::factory()->create();
    $user->createToken('this-device');
    $user->createToken('another-device');

    test()->authService->logout(null);
    test()->authService->logout(new TransientToken());

    expect($user->tokens()->count())->toBe(2);
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
