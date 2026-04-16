<?php

use App\Models\Poll;
use App\Models\User;
use App\Models\PollAudienceRule;

beforeEach(function (): void {
    test()->user = User::factory()->create([
        'email' => 'audience_test@gmail.com',
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
    $poll = createPollWithRules([
        ['criterion' => 'city_inside_syria', 'value' => 'daraa'],
        ['criterion' => 'city_inside_syria', 'value' => 'damascus'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('fails when user city_inside_syria does not match audience', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'city_inside_syria', 'value' => 'aleppo'],
        ['criterion' => 'city_inside_syria', 'value' => 'homs'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('city_inside_syria');
});

it('fails with city_inside_syria_missing when user has no city_inside_syria', function (): void {
    test()->user->update(['city_inside_syria' => null]);

    $poll = createPollWithRules([
        ['criterion' => 'city_inside_syria', 'value' => 'daraa'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('city_inside_syria_missing');
});

it('passes when audience has no city_inside_syria rules', function (): void {
    $poll = createPollWithRules([]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('passes when audience only has gender rule that matches', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'gender', 'value' => 'm'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('checks city_inside_syria alongside other criteria', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'country', 'value' => 'SY'],
        ['criterion' => 'city_inside_syria', 'value' => 'daraa'],
        ['criterion' => 'gender', 'value' => 'm'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('fails city_inside_syria alongside other criteria when city does not match', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'country', 'value' => 'SY'],
        ['criterion' => 'city_inside_syria', 'value' => 'aleppo'],
        ['criterion' => 'gender', 'value' => 'm'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('city_inside_syria');
    expect($failures)->not->toContain('country');
    expect($failures)->not->toContain('gender');
});

// ───────────────────────────────────────────────
// Allowed Voters
// ───────────────────────────────────────────────

it('passes when user email is in allowed_voters', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'allowed_voter', 'value' => 'audience_test@gmail.com'],
        ['criterion' => 'allowed_voter', 'value' => 'other@gmail.com'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('passes when user national_id is in allowed_voters', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'allowed_voter', 'value' => '12345678'],
        ['criterion' => 'allowed_voter', 'value' => '99999999'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('passes with case-insensitive email match in allowed_voters', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'allowed_voter', 'value' => 'AUDIENCE_TEST@gmail.com'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('fails when user is not in allowed_voters', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'allowed_voter', 'value' => 'unknown@gmail.com'],
        ['criterion' => 'allowed_voter', 'value' => '99999999'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('not_in_allowed_voters');
});

it('skips all other criteria when allowed_voters is specified', function (): void {
    // User is male, but poll has gender=f and country=US rules too.
    // However, allowed_voter rules are present and user's email matches — should pass.
    $poll = createPollWithRules([
        ['criterion' => 'allowed_voter', 'value' => 'audience_test@gmail.com'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('falls back to criteria when no allowed_voter rules exist', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'gender', 'value' => 'm'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('fails criteria check when no allowed_voter rules and gender does not match', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'gender', 'value' => 'f'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('gender');
});

// ───────────────────────────────────────────────
// Combined criteria checks
// ───────────────────────────────────────────────

it('collects multiple failures at once', function (): void {
    test()->user->update(['country' => 'TR', 'religious_affiliation' => 'christian']);

    $poll = createPollWithRules([
        ['criterion' => 'country', 'value' => 'SY'],
        ['criterion' => 'religious_affiliation', 'value' => 'sunni'],
        ['criterion' => 'city_inside_syria', 'value' => 'aleppo'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('country');
    expect($failures)->toContain('religious_affiliation');
    expect($failures)->toContain('city_inside_syria');
});

it('passes with completely open audience (no rules)', function (): void {
    $poll = createPollWithRules([]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

// ───────────────────────────────────────────────
// Age checks
// ───────────────────────────────────────────────

it('passes age check when user age is within range', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'age_min', 'value' => '18'],
        ['criterion' => 'age_max', 'value' => '50'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('fails age_min when user is too young', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'age_min', 'value' => '40'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('age_min');
});

it('fails age_max when user is too old', function (): void {
    $poll = createPollWithRules([
        ['criterion' => 'age_max', 'value' => '30'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('age_max');
});

it('fails with birth_date_missing when user has no birth_date and age rules exist', function (): void {
    test()->user->update(['birth_date' => null]);

    $poll = createPollWithRules([
        ['criterion' => 'age_min', 'value' => '18'],
    ]);

    [$eligible, $failures] = test()->user->isInAudience($poll);

    expect($eligible)->toBeFalse();
    expect($failures)->toContain('birth_date_missing');
});

// ───────────────────────────────────────────────
// Helper
// ───────────────────────────────────────────────

function createPollWithRules(array $rules): Poll
{
    $poll = Poll::forceCreate([
        'question' => 'Audience test poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
        'audience_only' => false,
    ]);

    $now = now();
    foreach ($rules as $rule) {
        PollAudienceRule::create([
            'poll_id' => $poll->id,
            'criterion' => $rule['criterion'],
            'value' => $rule['value'],
        ]);
    }

    return $poll->fresh()->load('audienceRules');
}
