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
