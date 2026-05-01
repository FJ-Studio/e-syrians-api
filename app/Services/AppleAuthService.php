<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies and decodes Apple identity tokens (JWT) from native and web
 * "Sign in with Apple" flows. Mirrors the implementation in foundhere-api.
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
     * @return array{id: string, name: string, email: string, avatar: null}|array{}
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
        ];
    }

    /**
     * Fetch Apple's public keys (JWKS) for JWT verification.
     * Cached for 24 hours to avoid repeated HTTP requests.
     */
    private static function getApplePublicKeys(): array
    {
        return Cache::remember(self::JWKS_CACHE_KEY, self::JWKS_CACHE_TTL, function () {
            $response = Http::get(self::APPLE_KEYS_URL);

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
