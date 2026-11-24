<?php

use App\Models\User;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use GuzzleHttp\HandlerStack;
use App\Services\AuthService;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

function googleIdentityFixture(array $overrides = [], bool $wrongSignature = false): string
{
    config(['services.google.client_id' => 'e-syrians-fixture-client', 'services.google.client_secret' => 'fixture', 'services.google.redirect' => 'https://example.test/callback']);
    Socialite::forgetDrivers();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $jwk = ['kty' => 'RSA', 'kid' => 'fixture', 'alg' => 'RS256', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])];
    $provider = Socialite::driver('google');
    expect($provider)->toBeInstanceOf(GoogleProvider::class);
    $provider->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR)),
    ]))]));
    $claims = [
        'iss' => 'https://accounts.google.com', 'aud' => 'e-syrians-fixture-client',
        'sub' => 'fixture-subject', 'name' => 'Google Fixture', 'email' => 'google-provider-fixture@gmail.com',
        'email_verified' => true, 'iat' => time() - 10, 'exp' => time() + 600,
        ...$overrides,
    ];
    $signingKey = $wrongSignature ? openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]) : $key;
    openssl_pkey_export($signingKey, $pem);

    return JWT::encode($claims, $pem, 'RS256', 'fixture');
}

it('authenticates a genuinely signed Google ID token through the registered provider', function (): void {
    $email = 'google-provider-'.strtolower(Str::random(10)).'@gmail.com';
    $token = googleIdentityFixture(['email' => $email]);
    $result = resolve(AuthService::class)->authenticateViaSocialProvider('google', $token);

    expect($result)->not->toBeNull()
        ->and($result['user']->email)->toBe($email)
        ->and($result['user']->google_id)->toBe('fixture-subject');
});

it('rejects a soft-deleted Google email without recreating or restoring its account', function (?string $subject, bool $verified): void {
    $email = 'deleted-google-fixture@gmail.com';
    $user = User::factory()->create([
        'email' => $email,
        'google_id' => $subject,
        'email_verified_at' => $verified ? now() : null,
    ]);
    $user->delete();
    $count = User::withTrashed()->count();
    $token = googleIdentityFixture(['email' => $email]);

    $this->postJson('/users/login/social', ['provider' => 'google', 'token' => $token])
        ->assertUnauthorized()
        ->assertJsonMissingPath('data.token');

    $this->assertSoftDeleted('users', ['id' => $user->id]);
    expect(User::withTrashed()->count())->toBe($count)
        ->and(User::withTrashed()->findOrFail($user->id)->google_id)->toBe($subject)
        ->and($user->tokens()->count())->toBe(0);
})->with([
    'previously linked' => ['fixture-subject', true],
    'not yet linked' => [null, true],
    'conflicting identity' => ['another-google-subject', true],
    'unverified local email' => [null, false],
]);

it('reuses a verified active Google account instead of inserting a duplicate email', function (?string $subject): void {
    $email = 'active-google-fixture@gmail.com';
    $user = User::factory()->create(['email' => $email, 'google_id' => $subject, 'email_verified_at' => now()]);
    $count = User::withTrashed()->count();

    foreach (range(1, 2) as $_) {
        $token = googleIdentityFixture(['email' => $email]);
        $this->postJson('/users/login/social', ['provider' => 'google', 'token' => $token])
            ->assertOk()
            ->assertJsonPath('data.user.uuid', (string) $user->uuid)
            ->assertJsonStructure(['data' => ['token']]);
    }

    expect(User::withTrashed()->count())->toBe($count)
        ->and($user->fresh()->google_id)->toBe('fixture-subject');
})->with(['already linked' => ['fixture-subject'], 'first Google login' => [null]]);

it('keeps a pending-deletion Google account on its existing grace-period workflow', function (): void {
    $email = 'pending-deletion-google-fixture@gmail.com';
    $user = User::factory()->create([
        'email' => $email,
        'google_id' => 'fixture-subject',
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);
    $count = User::withTrashed()->count();
    $token = googleIdentityFixture(['email' => $email]);

    $this->postJson('/users/login/social', ['provider' => 'google', 'token' => $token])
        ->assertOk()
        ->assertJsonPath('data.user.uuid', (string) $user->uuid);

    expect($user->fresh()->hasPendingDeletion())->toBeTrue()
        ->and($user->fresh()->trashed())->toBeFalse()
        ->and(User::withTrashed()->count())->toBe($count);
});

it('allows fresh Google registration after the old account was permanently deleted', function (): void {
    $email = 'hard-deleted-google-fixture@gmail.com';
    $user = User::factory()->create(['email' => $email, 'google_id' => 'fixture-subject']);
    $previousUuid = (string) $user->uuid;
    $user->forceDelete();
    $count = User::withTrashed()->count();
    $token = googleIdentityFixture(['email' => $email]);

    $this->postJson('/users/login/social', ['provider' => 'google', 'token' => $token])
        ->assertOk()
        ->assertJsonStructure(['data' => ['user' => ['uuid'], 'token']]);

    $registered = User::where('email', $email)->firstOrFail();
    expect((string) $registered->uuid)->not->toBe($previousUuid)
        ->and($registered->google_id)->toBe('fixture-subject')
        ->and($registered->hasVerifiedEmail())->toBeTrue()
        ->and($registered->hasRole('citizen'))->toBeTrue()
        ->and(User::withTrashed()->count())->toBe($count + 1);
});

it('does not bypass two-factor protection when linking an active Google email', function (): void {
    $email = 'two-factor-google-fixture@gmail.com';
    $user = User::factory()->create([
        'email' => $email,
        'google_id' => null,
        'two_factor_enabled' => true,
        'two_factor_confirmed_at' => now(),
    ]);
    $count = User::withTrashed()->count();
    $token = googleIdentityFixture(['email' => $email]);

    $this->postJson('/users/login/social', ['provider' => 'google', 'token' => $token])->assertUnauthorized();

    expect($user->fresh()->google_id)->toBeNull()
        ->and($user->tokens()->count())->toBe(0)
        ->and(User::withTrashed()->count())->toBe($count);
});

it('rejects a Google token with invalid signature, issuer, audience, expiry, or email proof', function (array $claims, bool $wrongSignature): void {
    $token = googleIdentityFixture($claims, $wrongSignature);
    $before = User::query()->count();

    expect(resolve(AuthService::class)->authenticateViaSocialProvider('google', $token))->toBeNull()
        ->and(User::query()->count())->toBe($before);
})->with([
    'signature' => [[], true],
    'issuer' => [['iss' => 'https://untrusted.test'], false],
    'audience' => [['aud' => 'another-app'], false],
    'expired' => [['exp' => 1], false],
    'unverified email' => [['email_verified' => false], false],
]);
