<?php

declare(strict_types=1);

use App\Enums\AudienceEntryTypeEnum;

/*
|--------------------------------------------------------------------------
| AudienceEntryTypeEnum::detect() — classification contract
|--------------------------------------------------------------------------
|
| detect() is the single source of truth for "what kind of identifier
| is this string" — it feeds both the FormRequest per-entry validator
| and the AudienceService's normalise-and-store step. If the rules ever
| drift between those two, malformed rows land in the DB, so we lock
| the behavior down with a small parameterised suite.
*/

it('classifies well-formed emails as Email', function (): void {
    expect(AudienceEntryTypeEnum::detect('foo@bar.com'))
        ->toBe(AudienceEntryTypeEnum::Email);
    expect(AudienceEntryTypeEnum::detect('a.b+c@sub.example.co'))
        ->toBe(AudienceEntryTypeEnum::Email);
});

it('classifies purely numeric strings within the length window as NationalId', function (): void {
    expect(AudienceEntryTypeEnum::detect('12345'))
        ->toBe(AudienceEntryTypeEnum::NationalId);
    expect(AudienceEntryTypeEnum::detect('12345678901'))
        ->toBe(AudienceEntryTypeEnum::NationalId);
});

it('returns null for values that are neither', function (): void {
    expect(AudienceEntryTypeEnum::detect(''))->toBeNull();
    expect(AudienceEntryTypeEnum::detect('abc'))->toBeNull();
    expect(AudienceEntryTypeEnum::detect('123'))->toBeNull();          // too short
    expect(AudienceEntryTypeEnum::detect('123456789012345678901'))->toBeNull(); // too long
    expect(AudienceEntryTypeEnum::detect('foo@'))->toBeNull();
});
