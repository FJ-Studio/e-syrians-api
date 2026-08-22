<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    test()->user = User::factory()->create([
        'email' => 'mig_test@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);
    test()->user->assignRole('citizen');

    // Re-add the `audience` JSON column — the drop migration runs during the
    // normal test migration pass, so we simulate the pre-drop state here.
    if (! Schema::hasColumn('polls', 'audience')) {
        Schema::table('polls', function ($table): void {
            $table->json('audience')->nullable();
        });
    }

    // Clear any rules carried over from prior tests/seeders.
    DB::table('poll_audience_rules')->delete();
});

function runAudienceJsonToRulesMigration(): void
{
    $migration = require database_path('migrations/2026_04_13_000003_migrate_poll_audience_json_to_rules.php');
    $migration->up();
}

function insertLegacyPoll(int $userId, array $audience): int
{
    return DB::table('polls')->insertGetId([
        'question' => 'Legacy poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => $userId,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience_only' => false,
        'is_private' => false,
        'audience' => json_encode($audience),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('migrates multi-value criteria into one row per value', function (): void {
    $pollId = insertLegacyPoll(test()->user->id, [
        'gender' => ['m', 'f'],
        'country' => ['TR', 'SY', 'LB'],
    ]);

    runAudienceJsonToRulesMigration();

    $rules = DB::table('poll_audience_rules')->where('poll_id', $pollId)->get();
    expect($rules)->toHaveCount(5);

    $genderValues = $rules->where('criterion', 'gender')->pluck('value')->all();
    expect($genderValues)->toContain('m')->toContain('f');

    $countryValues = $rules->where('criterion', 'country')->pluck('value')->all();
    expect($countryValues)->toContain('TR')->toContain('SY')->toContain('LB');
});

it('migrates age range when non-default', function (): void {
    $pollId = insertLegacyPoll(test()->user->id, [
        'age_range' => ['min' => 18, 'max' => 65],
    ]);

    runAudienceJsonToRulesMigration();

    $rules = DB::table('poll_audience_rules')->where('poll_id', $pollId)->get();
    expect($rules->where('criterion', 'age_min')->first()->value)->toBe('18');
    expect($rules->where('criterion', 'age_max')->first()->value)->toBe('65');
});

it('skips default age range 13-120', function (): void {
    $pollId = insertLegacyPoll(test()->user->id, [
        'age_range' => ['min' => 13, 'max' => 120],
    ]);

    runAudienceJsonToRulesMigration();

    expect(DB::table('poll_audience_rules')->where('poll_id', $pollId)->count())->toBe(0);
});

it('migrates only age_min when only min is non-default', function (): void {
    $pollId = insertLegacyPoll(test()->user->id, [
        'age_range' => ['min' => 21, 'max' => 120],
    ]);

    runAudienceJsonToRulesMigration();

    $rules = DB::table('poll_audience_rules')->where('poll_id', $pollId)->get();
    expect($rules)->toHaveCount(1);
    expect($rules->first()->criterion)->toBe('age_min');
    expect($rules->first()->value)->toBe('21');
});

it('migrates allowed_voters as allowed_voter (singular) rows', function (): void {
    $pollId = insertLegacyPoll(test()->user->id, [
        'allowed_voters' => ['a@gmail.com', 'b@gmail.com', '12345678'],
    ]);

    runAudienceJsonToRulesMigration();

    $rules = DB::table('poll_audience_rules')
        ->where('poll_id', $pollId)
        ->where('criterion', 'allowed_voter')
        ->pluck('value')
        ->all();

    expect($rules)->toHaveCount(3);
    expect($rules)->toContain('a@gmail.com')->toContain('b@gmail.com')->toContain('12345678');
});

it('deduplicates repeated values within the same criterion', function (): void {
    $pollId = insertLegacyPoll(test()->user->id, [
        'country' => ['TR', 'TR', 'SY'],
    ]);

    runAudienceJsonToRulesMigration();

    $values = DB::table('poll_audience_rules')
        ->where('poll_id', $pollId)
        ->where('criterion', 'country')
        ->pluck('value')
        ->all();

    expect($values)->toHaveCount(2);
});

it('ignores polls with empty audience', function (): void {
    $pollId = insertLegacyPoll(test()->user->id, []);

    runAudienceJsonToRulesMigration();

    expect(DB::table('poll_audience_rules')->where('poll_id', $pollId)->count())->toBe(0);
});

it('ignores polls with null audience', function (): void {
    $pollId = DB::table('polls')->insertGetId([
        'question' => 'Null audience poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience_only' => false,
        'is_private' => false,
        'audience' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runAudienceJsonToRulesMigration();

    expect(DB::table('poll_audience_rules')->where('poll_id', $pollId)->count())->toBe(0);
});

it('migrates a realistic combined audience', function (): void {
    $pollId = insertLegacyPoll(test()->user->id, [
        'gender' => ['m'],
        'country' => ['SY', 'TR'],
        'hometown' => ['damascus'],
        'ethnicity' => ['arab'],
        'religious_affiliation' => ['sunni'],
        'province' => ['daraa'],
        'age_range' => ['min' => 18, 'max' => 65],
    ]);

    runAudienceJsonToRulesMigration();

    $count = DB::table('poll_audience_rules')->where('poll_id', $pollId)->count();
    // 1 gender + 2 country + 1 hometown + 1 ethnicity + 1 religious_affiliation + 1 province + age_min + age_max
    expect($count)->toBe(9);
});
