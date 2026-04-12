<?php

use App\Models\User;

beforeEach(function (): void {
    test()->user = User::factory()->create([
        'email' => 'audience_test@example.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'SY',
        'ethnicity' => 'arab',
        'religious_affiliation' => 'sunni',
        'city_inside_syria' => 'daraa',
        'national_id' => '12345678',
    ]);
    test()->user->assignRole('citizen');
});

// ───────────────────────────────────────────────
// City Inside Syria
// ───────────────────────────────────────────────

it('passes when user city_inside_syria matches audience', function (): void {
    $audience = ['city_inside_syria' => ['daraa', 'damascus']];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('fails when user city_inside_syria does not match audience', function (): void {
    $audience = ['city_inside_syria' => ['aleppo', 'homs']];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('city_inside_syria');
});

it('fails with city_inside_syria_missing when user has no city_inside_syria', function (): void {
    test()->user->update(['city_inside_syria' => null]);

    $audience = ['city_inside_syria' => ['daraa']];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('city_inside_syria_missing');
});

it('passes when audience city_inside_syria is empty array', function (): void {
    $audience = ['city_inside_syria' => []];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('passes when audience has no city_inside_syria key', function (): void {
    $audience = ['gender' => ['m']];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('checks city_inside_syria alongside other criteria', function (): void {
    $audience = [
        'country' => ['SY'],
        'city_inside_syria' => ['daraa'],
        'gender' => ['m'],
    ];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('fails city_inside_syria alongside other criteria when city does not match', function (): void {
    $audience = [
        'country' => ['SY'],
        'city_inside_syria' => ['aleppo'],
        'gender' => ['m'],
    ];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('city_inside_syria');
    expect($failures)->not->toContain('country');
    expect($failures)->not->toContain('gender');
});

// ───────────────────────────────────────────────
// Allowed Voters
// ───────────────────────────────────────────────

it('passes when user email is in allowed_voters', function (): void {
    $audience = ['allowed_voters' => ['audience_test@example.com', 'other@example.com']];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('passes when user national_id is in allowed_voters', function (): void {
    $audience = ['allowed_voters' => ['12345678', '99999999']];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('passes with case-insensitive email match in allowed_voters', function (): void {
    $audience = ['allowed_voters' => ['AUDIENCE_TEST@EXAMPLE.COM']];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('fails when user is not in allowed_voters', function (): void {
    $audience = ['allowed_voters' => ['unknown@example.com', '99999999']];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('not_in_allowed_voters');
});

it('skips all other criteria when allowed_voters is specified', function (): void {
    // User is male, but audience gender is female.
    // However, allowed_voters is set and user's email matches — should pass.
    $audience = [
        'allowed_voters' => ['audience_test@example.com'],
        'gender' => ['f'],
        'country' => ['US'],
    ];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('falls back to criteria when allowed_voters is empty array', function (): void {
    $audience = [
        'allowed_voters' => [],
        'gender' => ['m'],
    ];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('falls back to criteria when allowed_voters is not set', function (): void {
    $audience = [
        'gender' => ['f'],
    ];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('gender');
});

// ───────────────────────────────────────────────
// Combined criteria checks
// ───────────────────────────────────────────────

it('collects multiple failures at once', function (): void {
    test()->user->update(['country' => 'TR', 'religious_affiliation' => 'christian']);

    $audience = [
        'country' => ['SY'],
        'religious_affiliation' => ['sunni'],
        'city_inside_syria' => ['aleppo'],
    ];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('country');
    expect($failures)->toContain('religious_affiliation');
    expect($failures)->toContain('city_inside_syria');
});

it('passes with completely open audience', function (): void {
    $audience = [];

    [$eligible, $failures] = test()->user->isInAudience($audience);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});
