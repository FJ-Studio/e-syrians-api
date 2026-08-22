<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

final class RecoveryCodeService
{
    private const CODE_LENGTH = 12;
    private const SEGMENT_LENGTH = 4;
    private const CODE_COUNT = 8;

    /**
     * Generate a set of recovery codes (pure — does not touch the user).
     */
    public static function generateCodes(int $count = self::CODE_COUNT): array
    {
        return collect(range(1, $count))
            ->map(fn () => self::generateCode())
            ->all();
    }

    /**
     * Generate a single recovery code in the format XXXX-XXXX-XXXX.
     */
    public static function generateCode(): string
    {
        do {
            $rawCode = Str::upper(Str::random(self::CODE_LENGTH));
        } while (!preg_match('/[A-Z]/', $rawCode) || !preg_match('/\d/', $rawCode));

        return implode('-', mb_str_split($rawCode, self::SEGMENT_LENGTH));
    }

    /**
     * Issue a fresh batch of recovery codes for the given user and persist
     * both the codes AND the original count.
     *
     * Why a single entry point: the codes list is mutated as the user
     * consumes recovery codes during 2FA fallback sign-ins (see
     * `TwoFactorService::verifyAndConsumeRecoveryCode`). Without a paired
     * `recovery_codes_total` snapshot it's impossible to display a
     * "remaining of total" counter — count(remaining) just equals the
     * current array length. Every code-generation site (initial 2FA
     * confirm, lazy load on first GET, manual regenerate) should funnel
     * through this method so the snapshot stays consistent.
     *
     * @return string[] The newly-issued codes (so callers can return them
     *                  to the client in the same response).
     */
    public static function issueFor(User $user, int $count = self::CODE_COUNT): array
    {
        $codes = self::generateCodes($count);

        $user->update([
            'recovery_codes' => $codes,
            'recovery_codes_total' => count($codes),
        ]);

        return $codes;
    }
}
