<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class TwoFactorChallengeService
{
    private const CACHE_PREFIX = '2fa_challenge:';
    private const CHALLENGE_TTL_MINUTES = 5;

    /**
     * Create a 2FA challenge for a user.
     *
     * @return array{challenge_token: string, expires_at: string}
     */
    public static function createChallenge(int $userId, ?string $deviceName = null): array
    {
        $challengeToken = Str::random(64);
        $expiresAt = now()->addMinutes(self::CHALLENGE_TTL_MINUTES);

        Cache::put(
            self::CACHE_PREFIX . $challengeToken,
            [
                'user_id' => $userId,
                'device_name' => $deviceName ?? 'unknown',
                'created_at' => now()->toIso8601String(),
            ],
            $expiresAt
        );

        return [
            'challenge_token' => $challengeToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Verify and consume a 2FA challenge.
     *
     * @return array{user_id: int, device_name: string}|null
     */
    public static function verifyChallenge(string $challengeToken): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $challengeToken;
        $challengeData = Cache::get($cacheKey);

        if ($challengeData === null) {
            return null;
        }

        // Consume the challenge (one-time use)
        Cache::forget($cacheKey);

        return $challengeData;
    }
}
