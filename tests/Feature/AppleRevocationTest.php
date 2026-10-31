<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Services\AuthService;
use App\Models\AppleRefreshToken;
use App\Services\AppleAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Jobs\HardDeleteExpiredAccountsJob;
use Illuminate\Support\Facades\Exceptions;
use App\Http\Requests\User\SocialLoginRequest;
use App\Contracts\AccountDeletionServiceContract;

function appleTestIdentity(array $overrides = []): string
{
    return JWT::encode(array_replace([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.esyrians.app',
        'sub' => 'apple-test-subject',
        'email' => 'apple-revocation@example.com',
        'iat' => time(),
        'exp' => time() + 600,
    ], $overrides), test()->appleIdentityKey, 'RS256', 'test-apple-key');
}

beforeEach(function (): void {
    // Real local signatures; all Apple network calls are fake.
    $identityKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($identityKey, $this->appleIdentityKey);
    $details = openssl_pkey_get_details($identityKey);
    $base64url = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    Cache::put('apple_jwks', ['keys' => [[
        'kty' => 'RSA', 'kid' => 'test-apple-key', 'alg' => 'RS256', 'use' => 'sig',
        'n' => $base64url($details['rsa']['n']), 'e' => $base64url($details['rsa']['e']),
    ]]], 600);

    $signingKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($signingKey, $privateKey);
    $this->appleClientPublicKey = openssl_pkey_get_details($signingKey)['key'];
    config()->set('services.apple', [
        'client_id' => 'com.esyrians.app',
        'web_client_id' => 'com.esyrians.web',
        'team_id' => 'TESTTEAM01',
        'key_id' => 'TESTKEY001',
        'private_key' => str_replace("\n", '\n', $privateKey),
        'redirect' => 'https://www.e-syrians.com',
    ]);
    Http::preventStrayRequests();
});

it('requires the Apple authorization code without changing Google validation', function (): void {
    $this->postJson('/users/login/social', ['provider' => 'apple', 'token' => 'identity'])
        ->assertUnprocessable()->assertJsonPath('success', false);

    $request = new SocialLoginRequest();
    expect(validator(['provider' => 'google', 'token' => 'access-token'], $request->rules())->passes())->toBeTrue();
    Http::assertNothingSent();
});

it('exchanges native Apple codes and stores only an encrypted hidden refresh token', function (): void {
    $identity = appleTestIdentity();
    Http::fake(['https://appleid.apple.com/auth/token' => Http::response([
        'id_token' => $identity, 'refresh_token' => 'native-refresh-secret',
    ])]);

    $response = $this->postJson('/users/login/social', [
        'provider' => 'apple', 'token' => $identity, 'authorization_code' => 'single-use-code', 'name' => 'Test Citizen',
    ])->assertOk()->assertJsonStructure(['data' => ['user', 'token']]);

    $grant = AppleRefreshToken::sole();
    expect($grant->refresh_token)->toBe('native-refresh-secret')
        ->and($grant->toArray())->not->toHaveKey('refresh_token')
        ->and(DB::table('apple_refresh_tokens')->value('refresh_token'))->not->toBe('native-refresh-secret')
        ->and($response->getContent())->not->toContain('native-refresh-secret');
    Http::assertSent(function ($request): bool {
        $secret = JWT::decode($request['client_secret'], new Key($this->appleClientPublicKey, 'ES256'));

        return $request['code'] === 'single-use-code'
            && $request['grant_type'] === 'authorization_code'
            && $request['client_id'] === 'com.esyrians.app'
            && ! isset($request['redirect_uri'])
            && str_contains($request->header('Content-Type')[0], 'application/x-www-form-urlencoded')
            && $secret->sub === 'com.esyrians.app' && $secret->iss === 'TESTTEAM01'
            && $secret->aud === 'https://appleid.apple.com' && $secret->exp - $secret->iat === 300;
    });
});

it('retains every distinct native and web grant including repeat sign-ins', function (): void {
    $nativeIdentity = appleTestIdentity();
    $webIdentity = appleTestIdentity(['aud' => 'com.esyrians.web']);
    Http::fake(['https://appleid.apple.com/auth/token' => Http::sequence()
        ->push(['id_token' => $nativeIdentity, 'refresh_token' => 'native-first'])
        ->push(['id_token' => $webIdentity, 'refresh_token' => 'web-refresh'])
        ->push(['id_token' => $nativeIdentity, 'refresh_token' => 'native-latest'])
        ->push(['id_token' => $nativeIdentity, 'refresh_token' => 'native-latest'])]);
    $service = resolve(AuthService::class);
    $first = $service->authenticateViaSocialProvider('apple', $nativeIdentity, null, 'code-1');
    $web = $service->authenticateViaSocialProvider('apple', $webIdentity, null, 'code-2');
    $again = $service->authenticateViaSocialProvider('apple', $nativeIdentity, null, 'code-3');
    $service->authenticateViaSocialProvider('apple', $nativeIdentity, null, 'code-4');

    expect($web['user']->id)->toBe($first['user']->id)
        ->and($again['user']->id)->toBe($first['user']->id)
        ->and(AppleRefreshToken::count())->toBe(3)
        ->and(AppleRefreshToken::where('client_id', 'com.esyrians.app')->get()->pluck('refresh_token')->all())->toBe(['native-first', 'native-latest']);
    Http::assertSent(fn ($request) => $request['client_id'] === 'com.esyrians.web'
        && $request['redirect_uri'] === 'https://www.e-syrians.com'
        && JWT::decode($request['client_secret'], new Key($this->appleClientPublicKey, 'ES256'))->sub === 'com.esyrians.web');
});

it('can sign with a file-backed key without inline private-key configuration', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('apple-test.p8', str_replace('\\n', "\n", config('services.apple.private_key')));
    config()->set('services.apple.private_key', null);
    config()->set('services.apple.private_key_path', Storage::disk('local')->path('apple-test.p8'));
    $identity = appleTestIdentity();
    Http::fake(['https://appleid.apple.com/auth/token' => Http::response([
        'id_token' => $identity, 'refresh_token' => 'file-backed-refresh',
    ])]);

    $result = resolve(AuthService::class)->authenticateViaSocialProvider('apple', $identity, null, 'file-backed-code');
    expect($result)->not->toBeNull();
    Http::assertSent(fn ($request) => JWT::decode($request['client_secret'], new Key($this->appleClientPublicKey, 'ES256'))->sub === 'com.esyrians.app');
});

it('prefers the inline key when a key file is also configured', function (): void {
    config()->set('services.apple.private_key_path', '/nonexistent/apple-sign-in.p8');
    $identity = appleTestIdentity();
    Http::fake(['https://appleid.apple.com/auth/token' => Http::response([
        'id_token' => $identity, 'refresh_token' => 'inline-refresh',
    ])]);
    expect(AppleAuthService::exchangeAuthorizationCode($identity, 'inline-code'))->not->toBeNull();
});

it('fails safely for a missing key file without exposing its path or making a token request', function (): void {
    config()->set('services.apple.private_key', null);
    config()->set('services.apple.private_key_path', '/nonexistent/apple-sign-in.p8');
    $response = $this->postJson('/users/login/social', [
        'provider' => 'apple', 'token' => appleTestIdentity(), 'authorization_code' => 'code',
    ])->assertStatus(503);
    expect($response->getContent())->not->toContain('/nonexistent/');
    Http::assertNothingSent();
});

it('rejects identity and code pairings for different subjects or clients', function (array $claims): void {
    Http::fake(['https://appleid.apple.com/auth/token' => Http::response([
        'id_token' => appleTestIdentity($claims), 'refresh_token' => 'must-not-save',
    ])]);
    $result = resolve(AuthService::class)->authenticateViaSocialProvider('apple', appleTestIdentity(), null, 'mismatched-code');
    expect($result)->toBeNull()
        ->and(AppleRefreshToken::count())->toBe(0)
        ->and(User::where('apple_id', 'apple-test-subject')->exists())->toBeFalse();
})->with([
    'subject' => [['sub' => 'someone-else']],
    'client' => [['aud' => 'com.esyrians.web']],
]);

it('rejects invalid identities before exchanging a code', function (): void {
    expect(AppleAuthService::exchangeAuthorizationCode('forged.identity.token', 'code'))->toBeNull()
        ->and(AppleAuthService::exchangeAuthorizationCode(appleTestIdentity(['aud' => 'unknown-app']), 'code'))->toBeNull();
    Http::assertNothingSent();
});

it('does not issue a session or save grants for expired or reused codes', function (): void {
    Http::fake(['https://appleid.apple.com/auth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $this->postJson('/users/login/social', [
        'provider' => 'apple', 'token' => appleTestIdentity(), 'authorization_code' => 'used-code',
    ])->assertUnauthorized();
    expect(AppleRefreshToken::count())->toBe(0)
        ->and(User::where('apple_id', 'apple-test-subject')->exists())->toBeFalse();
});

it('returns a safe service error for unavailable exchange or missing configuration', function (): void {
    Http::fake(['https://appleid.apple.com/auth/token' => Http::response(['secret' => 'must-not-leak'], 500)]);
    $payload = ['provider' => 'apple', 'token' => appleTestIdentity(), 'authorization_code' => 'code'];
    $response = $this->postJson('/users/login/social', $payload)->assertStatus(503);
    expect($response->getContent())->not->toContain('must-not-leak');
    config()->set('services.apple.private_key', '');
    $this->postJson('/users/login/social', $payload)->assertStatus(503);
    expect(AppleRefreshToken::count())->toBe(0);
});

it('keeps grants throughout scheduling and cancelling deletion', function (): void {
    $user = User::factory()->create(['password' => 'test-password', 'apple_id' => 'apple-test-subject']);
    AppleRefreshToken::create(['user_id' => $user->id, 'client_id' => 'com.esyrians.app', 'refresh_token' => 'keep-me']);
    $service = resolve(AccountDeletionServiceContract::class);
    $service->requestDeletion($user, 'test-password');
    $service->cancelDeletion($user, 'test-password');
    expect(AppleRefreshToken::sole()->refresh_token)->toBe('keep-me');
    Http::assertNothingSent();
});

it('allows pending Apple accounts to sign in for reactivation without revoking their grant', function (): void {
    $user = User::factory()->create([
        'apple_id' => 'apple-test-subject',
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);
    $identity = appleTestIdentity(['email' => $user->email]);
    Http::fake(['https://appleid.apple.com/auth/token' => Http::response([
        'id_token' => $identity, 'refresh_token' => 'pending-refresh',
    ])]);

    $this->postJson('/users/login/social', [
        'provider' => 'apple', 'token' => $identity, 'authorization_code' => 'pending-code',
    ])->assertOk()->assertJsonPath('data.user.deletion_scheduled_for', $user->deletion_scheduled_for->toIso8601String());
    expect(AppleRefreshToken::sole()->user_id)->toBe($user->id);
    Http::assertNotSent(fn ($request) => $request->url() === 'https://appleid.apple.com/auth/revoke');
});

it('revokes every client grant before permanent deletion and removes encrypted tokens', function (): void {
    Http::fake(['https://appleid.apple.com/auth/revoke' => Http::response('', 200)]);
    $user = User::factory()->create(['apple_id' => 'apple-test-subject']);
    foreach (['com.esyrians.app', 'com.esyrians.web'] as $clientId) {
        AppleRefreshToken::create(['user_id' => $user->id, 'client_id' => $clientId, 'refresh_token' => $clientId.'-refresh']);
    }
    AppleRefreshToken::create(['user_id' => $user->id, 'client_id' => 'com.esyrians.app', 'refresh_token' => 'another-native-session']);
    resolve(AccountDeletionServiceContract::class)->hardDeleteAccount($user);
    expect(User::withTrashed()->find($user->id))->toBeNull()->and(AppleRefreshToken::count())->toBe(0);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request['token'] === 'another-native-session');
    Http::assertSent(fn ($request) => $request->url() === 'https://appleid.apple.com/auth/revoke'
        && $request['token'] === 'com.esyrians.app-refresh' && $request['token_type_hint'] === 'refresh_token');
    Http::assertSent(fn ($request) => $request['client_id'] === 'com.esyrians.web'
        && JWT::decode($request['client_secret'], new Key($this->appleClientPublicKey, 'ES256'))->sub === 'com.esyrians.web');
});

it('preserves accounts and grants on revocation failure and succeeds on the next sweep', function (): void {
    Exceptions::fake();
    Http::fake(['https://appleid.apple.com/auth/revoke' => Http::sequence()->push('', 503)->push('', 200)]);
    $user = User::factory()->create([
        'apple_id' => 'apple-test-subject', 'deletion_requested_at' => now()->subDays(16), 'deletion_scheduled_for' => now()->subDay(),
    ]);
    AppleRefreshToken::create(['user_id' => $user->id, 'client_id' => 'com.esyrians.app', 'refresh_token' => 'retry-me']);
    $job = new HardDeleteExpiredAccountsJob();
    $job->handle(resolve(AccountDeletionServiceContract::class));
    expect(User::find($user->id))->not->toBeNull()->and(AppleRefreshToken::sole()->refresh_token)->toBe('retry-me');
    $job->handle(resolve(AccountDeletionServiceContract::class));
    expect(User::withTrashed()->find($user->id))->toBeNull()->and(AppleRefreshToken::count())->toBe(0);
});

it('still deletes a legacy Apple account without a stored refresh token', function (): void {
    $user = User::factory()->create(['apple_id' => 'legacy-subject']);
    resolve(AccountDeletionServiceContract::class)->hardDeleteAccount($user);
    expect(User::withTrashed()->find($user->id))->toBeNull();
    Http::assertNothingSent();
});
