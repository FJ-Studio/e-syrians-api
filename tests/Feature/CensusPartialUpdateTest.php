<?php

use App\Models\User;
use App\Enums\ProfileChangeTypeEnum;
use Illuminate\Support\Facades\Queue;
use App\Jobs\LogProfileChangeToBigQuery;

beforeEach(function (): void {
    Queue::fake();
    test()->user = User::factory()->create([
        'middle_name' => 'Original',
        'religious_affiliation' => 'sunni',
        'marital_status' => 'single',
        'languages' => 'arabic,english',
        'other_nationalities' => 'TR,US',
        'skills' => 'Existing skills',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);
});

it('accepts a middle-name-only save without requiring or clearing other census fields', function (): void {
    $verifiedAt = (string) test()->user->verified_at;
    $this->postJson(route('users.update.census'), [
        'middle_name' => 'Changed',
    ], authHeader(test()->user))->assertOk();

    $user = test()->user->fresh();
    expect($user->middle_name)->toBe('Changed')
        ->and($user->religious_affiliation)->toBe('sunni')
        ->and($user->marital_status)->toBe('single')
        ->and($user->languages)->toBe('arabic,english')
        ->and($user->other_nationalities)->toBe('TR,US')
        ->and($user->skills)->toBe('Existing skills')
        ->and((string) $user->verified_at)->toBe($verifiedAt)
        ->and($user->getReligionUpdatesCount())->toBe(0);

    $audit = $user->profileUpdates()->sole();
    expect($audit->change_type)->toBe(ProfileChangeTypeEnum::Regular->value)
        ->and($audit->changes)->toEqual(['middle_name' => ['old' => 'Original', 'new' => 'Changed']]);
    Queue::assertPushed(LogProfileChangeToBigQuery::class, 1);
});

it('accepts demographic data with the optional middle name omitted or blank', function (array $middleName): void {
    $this->postJson(route('users.update.census'), [
        ...$middleName,
        'religious_affiliation' => 'shia',
        'marital_status' => 'married',
    ], authHeader(test()->user))->assertOk();

    $user = test()->user->fresh();
    expect($user->middle_name)->toBe($middleName === [] ? 'Original' : null)
        ->and($user->religious_affiliation)->toBe('shia')
        ->and($user->marital_status)->toBe('married')
        ->and($user->languages)->toBe('arabic,english')
        ->and($user->other_nationalities)->toBe('TR,US')
        ->and($user->getReligionUpdatesCount())->toBe(1)
        ->and($user->profileUpdates()->count())->toBe(2);
})->with([
    'omitted' => [[]],
    'null' => [['middle_name' => null]],
    'empty input' => [['middle_name' => '']],
]);

it('allows every census field to be omitted', function (): void {
    $this->postJson(route('users.update.census'), [], authHeader(test()->user))->assertOk();

    $user = test()->user->fresh();
    expect($user->middle_name)->toBe('Original')
        ->and($user->religious_affiliation)->toBe('sunni')
        ->and($user->languages)->toBe('arabic,english')
        ->and($user->other_nationalities)->toBe('TR,US')
        ->and($user->getReligionUpdatesCount())->toBe(0);
});

it('allows optional census fields to be explicitly cleared', function (): void {
    $this->postJson(route('users.update.census'), [
        'middle_name' => null,
        'religious_affiliation' => null,
        'marital_status' => null,
        'languages' => null,
        'other_nationalities' => [],
        'skills' => null,
    ], authHeader(test()->user))->assertOk();

    $user = test()->user->fresh();
    expect($user->middle_name)->toBeNull()
        ->and($user->religious_affiliation)->toBeNull()
        ->and($user->marital_status)->toBeNull()
        ->and($user->languages)->toBe('')
        ->and($user->other_nationalities)->toBe('')
        ->and($user->skills)->toBeNull();
});

it('does not consume another religion change when the value is unchanged', function (): void {
    $this->postJson(route('users.update.census'), [
        'middle_name' => 'Changed',
        'religious_affiliation' => 'sunni',
    ], authHeader(test()->user))->assertOk();

    expect(test()->user->fresh()->getReligionUpdatesCount())->toBe(0)
        ->and(test()->user->profileUpdates()->count())->toBe(1);
});
