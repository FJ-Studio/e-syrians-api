<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

final class RecoveryCodeService
{
    private const CODE_LENGTH = 12;
    private const SEGMENT_LENGTH = 4;
    private const CODE_COUNT = 8;

    /**
     * Generate a set of recovery codes.
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
}
