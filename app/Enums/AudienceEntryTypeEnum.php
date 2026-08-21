<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Type of identifier stored in an audience entry.
 *
 * We deliberately support only two identifier shapes today:
 * emails and Syrian national IDs. Phones are excluded because the
 * poll creator rarely knows phone numbers of their target audience
 * with the same precision they know emails / IDs, and adding a
 * third format multiplies the format-detection edge cases without
 * a proven use case.
 *
 * The string values are the public API contract — mobile / web
 * clients render them as-is or via i18n keys `audiences.entry.type.*`.
 * Don't rename without a coordinated release.
 */
enum AudienceEntryTypeEnum: string
{
    case Email = 'email';
    case NationalId = 'national_id';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::NationalId => 'National ID',
        };
    }

    /**
     * Detect the type from a raw identifier string. The audience
     * feature accepts a mixed paste of emails + national IDs; this
     * decides which bucket each row falls into so the caller
     * doesn't have to.
     *
     * Detection rules:
     *   - PHP's `FILTER_VALIDATE_EMAIL` decides email-ness. Earlier
     *     versions used a naive `str_contains($x, '@')`, which
     *     misclassified `foo@` (and anything else with a stray `@`)
     *     as Email and then handed it downstream to the DB. The
     *     built-in filter is the same one Laravel's `email` rule
     *     uses, so acceptance stays consistent with the poll form.
     *   - Otherwise 5-20 digit strings map to national IDs (matches
     *     StorePollRequest's `allowed_voters` regex).
     *   - Returns `null` for anything that fits neither — the
     *     FormRequest translates that into a validation error.
     */
    public static function detect(string $identifier): ?self
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return null;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false) {
            return self::Email;
        }

        if (preg_match('/^\d{5,20}$/', $trimmed) === 1) {
            return self::NationalId;
        }

        return null;
    }
}
