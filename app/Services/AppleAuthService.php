<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use App\Models\AppleRefreshToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Verifies and decodes Apple identity tokens (JWT) from native and web
 * "Sign in with Apple" flows, and retains a revocable grant for deletion.
 *
 * Tokens are verified against Apple's public JWKS, then validated for issuer,
 * audience (must match a configured client_id or web_client_id), and required
 * claims (sub + email). The JWKS is cached for 24 hours.
 */
class AppleAuthService
{
    private const APPLE_KEYS_URL = 'https://appleid.apple.com/auth/keys';
    private const APPLE_ISSUER = 'https://appleid.apple.com';
    private const JWKS_CACHE_KEY = 'apple_jwks';
    private const JWKS_CACHE_TTL = 86400; // 24 hours

    /**
     * Decode and validate an Apple identity token, returning the user data
     * we need (Apple's stable user id + email). Returns an empty array if
     * the token is invalid for any reason.
     *
     * @return array{id: string, name: string, email: string, avatar: null, client_id: string}|array{}
     */
    public static function getUserDataFromIdentityToken(string $identityToken): array
    {
        try {
            $keys = self::getApplePublicKeys();
            $claims = JWT::decode($identityToken, JWK::parseKeySet($keys, 'RS256'));
        } catch (Throwable) {
            return [];
        }

        // Validate issuer
        if (($claims->iss ?? null) !== self::APPLE_ISSUER) {
            return [];
        }

        // Validate audience matches our app's bundle ID or web Services ID
        $allowedAudiences = array_filter([
            config('services.apple.client_id'),
            config('services.apple.web_client_id'),
        ]);
        if (! in_array($claims->aud ?? null, $allowedAudiences, true)) {
            return [];
        }

        if (empty($claims->sub) || empty($claims->email)) {
            return [];
        }

        return [
            'id' => (string) $claims->sub,
            // Apple doesn't return a name in the identity token after the first
            // sign-in. Fallback to email-localpart so the user record always
            // has a non-empty name.
            'name' => self::deriveNameFromEmail((string) $claims->email),
            'email' => (string) $claims->email,
            'avatar' => null,
            'client_id' => (string) $claims->aud,
        ];
    }

    /**
     * Both the signed identity and the single-use code must belong to the
     * same Apple subject and client. Never accept client_id from the browser.
     *
     * @return array{id: string, name: string, email: string, avatar: null, client_id: string, refresh_token: string}|null
     */
    public static function exchangeAuthorizationCode(string $identityToken, string $code): ?array
    {
        $identity = self::getUserDataFromIdentityToken($identityToken);
        if ($identity === [] || $code === '') {
            return null;
        }

        $clientId = $identity['client_id'];
        $parameters = [
            'client_id' => $clientId,
            'client_secret' => self::clientSecret($clientId),
            'code' => $code,
            'grant_type' => 'authorization_code',
        ];
        if ($clientId === config('services.apple.web_client_id')) {
            $redirect = config('services.apple.redirect');
            if (! is_string($redirect) || $redirect === '') {
                throw new HttpException(503, 'Apple sign-in is not configured.');
            }
            $parameters['redirect_uri'] = $redirect;
        }

        // Authorization codes are single-use: do not automatically retry.
        $response = self::post('token', $parameters);
        if (! $response->successful()) {
            if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
                return null;
            }
            throw new HttpException(503, 'Apple sign-in is temporarily unavailable.');
        }

        $idToken = $response->json('id_token');
        $refreshToken = $response->json('refresh_token');
        if (! is_string($idToken) || ! is_string($refreshToken) || $refreshToken === '') {
            throw new HttpException(503, 'Apple returned an incomplete token response.');
        }
        $exchangedIdentity = self::getUserDataFromIdentityToken($idToken);
        if ($exchangedIdentity === []
            || $exchangedIdentity['id'] !== $identity['id']
            || $exchangedIdentity['client_id'] !== $clientId) {
            return null;
        }

        return [...$identity, 'refresh_token' => $refreshToken];
    }

    /**
     * Called only at final deletion, not when scheduling/cancelling it.
     * A failure must bubble up so the daily sweep retains the encrypted
     * grant and retries on its next run instead of losing revocation data.
     */
    public static function revokeForUser(User $user): void
    {
        $grants = AppleRefreshToken::where('user_id', $user->id)->get();
        if ($grants->isEmpty() && $user->apple_id) {
            // Legacy accounts predate code exchange. Apple requires local
            // deletion even without a token; see docs/apple-account-deletion.md.
            Log::warning('Apple revocation unavailable for legacy account', ['user_id' => $user->id]);
        }

        foreach ($grants as $grant) {
            $response = self::post('revoke', [
                'client_id' => $grant->client_id,
                'client_secret' => self::clientSecret($grant->client_id),
                'token' => $grant->refresh_token,
                'token_type_hint' => 'refresh_token',
            ]);
            // Apple also returns 200 for a previously invalidated token,
            // making retry safe after a DB rollback or partial batch failure.
            if ($response->status() !== 200) {
                throw new HttpException(503, 'Apple token revocation failed; deletion will be retried.');
            }
        }
    }

    private static function clientSecret(string $clientId): string
    {
        if (! in_array($clientId, array_filter([
            config('services.apple.client_id'), config('services.apple.web_client_id'),
        ]), true)) {
            throw new HttpException(503, 'Apple client is not configured.');
        }

        $teamId = config('services.apple.team_id');
        $keyId = config('services.apple.key_id');
        $privateKey = config('services.apple.private_key');
        if (! is_string($privateKey) || $privateKey === '') {
            $path = config('services.apple.private_key_path');
            if (is_string($path) && $path !== '') {
                try {
                    $privateKey = File::get($path);
                } catch (Throwable) {
                    throw new HttpException(503, 'Apple signing key file is not readable.');
                }
            }
        }
        if (! is_string($teamId) || $teamId === '' || ! is_string($keyId) || $keyId === ''
            || ! is_string($privateKey) || $privateKey === '') {
            throw new HttpException(503, 'Apple sign-in is not configured.');
        }

        try {
            // Mint per-client secrets on demand: no six-month JWT to expire
            // between sign-in and deletion after the grace period.
            return JWT::encode([
                'iss' => $teamId,
                'iat' => time(),
                'exp' => time() + 300,
                'aud' => self::APPLE_ISSUER,
                'sub' => $clientId,
            ], str_replace('\\n', "\n", $privateKey), 'ES256', $keyId);
        } catch (Throwable) {
            throw new HttpException(503, 'Apple signing key is invalid.');
        }
    }

    private static function post(string $endpoint, array $parameters): Response
    {
        try {
            return Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)
                ->post(self::APPLE_ISSUER.'/auth/'.$endpoint, $parameters);
        } catch (ConnectionException) {
            // Do not chain the HTTP exception: request bodies contain secrets.
            throw new HttpException(503, 'Apple authentication service is unreachable.');
        }
    }

    /**
     * Fetch Apple's public keys (JWKS) for JWT verification.
     * Cached for 24 hours to avoid repeated HTTP requests.
     */
    private static function getApplePublicKeys(): array
    {
        return Cache::remember(self::JWKS_CACHE_KEY, self::JWKS_CACHE_TTL, function () {
            $response = Http::connectTimeout(5)->timeout(15)->get(self::APPLE_KEYS_URL);

            if (! $response->successful()) {
                throw new RuntimeException('Failed to fetch Apple public keys');
            }

            return $response->json();
        });
    }

    /**
     * Derive a human-friendly name from an email when Apple doesn't provide
     * one. Strips the domain and replaces separators with spaces.
     */
    private static function deriveNameFromEmail(string $email): string
    {
        $localPart = strstr($email, '@', true) ?: $email;

        return ucfirst(str_replace(['.', '_', '-'], ' ', $localPart));
    }
}
