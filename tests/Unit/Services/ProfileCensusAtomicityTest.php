<?php

use App\Models\User;
use App\Models\ProfileUpdate;
use App\Services\ProfileService;
use App\Enums\ProfileChangeTypeEnum;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use App\Jobs\LogProfileChangeToBigQuery;

beforeEach(function (): void {
    Queue::fake();
    test()->user = User::factory()->create([
        'middle_name' => 'Original',
        'religious_affiliation' => 'sunni',
    ]);
});

it('rolls back the census values and all audits when an audit insert fails', function (string $failingType): void {
    $event = 'eloquent.creating: '.ProfileUpdate::class;
    Event::listen($event, function (ProfileUpdate $audit) use ($failingType): void {
        if ($audit->change_type === $failingType) {
            throw new RuntimeException('Simulated census audit failure');
        }
    });

    try {
        expect(fn () => resolve(ProfileService::class)->updateCensusData(test()->user, [
            'middle_name' => 'Changed',
            'religious_affiliation' => 'shia',
        ]))->toThrow(RuntimeException::class, 'Simulated census audit failure');
    } finally {
        Event::forget($event);
    }

    $user = test()->user->fresh();
    expect($user->middle_name)->toBe('Original')
        ->and($user->religious_affiliation)->toBe('sunni')
        ->and($user->profileUpdates()->count())->toBe(0)
        ->and($user->getReligionUpdatesCount())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'regular audit fails' => ProfileChangeTypeEnum::Regular->value,
    'religion audit fails after regular audit succeeds' => ProfileChangeTypeEnum::Religion->value,
]);

it('saves census data with both audits and defers export jobs until commit', function (): void {
    resolve(ProfileService::class)->updateCensusData(test()->user, [
        'middle_name' => 'Changed',
        'religious_affiliation' => 'shia',
    ]);

    $user = test()->user->fresh();
    $audits = $user->profileUpdates()->orderBy('id')->get();
    expect($user->middle_name)->toBe('Changed')
        ->and($user->religious_affiliation)->toBe('shia')
        ->and($audits)->toHaveCount(2)
        ->and($audits[0]->change_type)->toBe(ProfileChangeTypeEnum::Regular->value)
        ->and($audits[1]->change_type)->toBe(ProfileChangeTypeEnum::Religion->value)
        ->and($audits[1]->changes)->toEqual([
            'religious_affiliation' => ['old' => 'sunni', 'new' => 'shia'],
        ])
        ->and($user->getReligionUpdatesCount())->toBe(1);

    Queue::assertPushed(LogProfileChangeToBigQuery::class, 2);
    foreach (Queue::pushed(LogProfileChangeToBigQuery::class) as $job) {
        expect($job->afterCommit)->toBeTrue();
    }
});
