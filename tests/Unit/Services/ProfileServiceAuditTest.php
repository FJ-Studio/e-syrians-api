<?php

use App\Models\User;
use App\Services\ProfileService;
use App\Enums\ProfileChangeTypeEnum;
use Illuminate\Support\Facades\Queue;
use App\Jobs\LogProfileChangeToBigQuery;
use App\Exceptions\UpdateLimitReachedException;

beforeEach(function (): void {
    Queue::fake();
    test()->profileService = resolve(ProfileService::class);
    test()->user = User::factory()->create([
        'name' => 'Ahmad',
        'surname' => 'Hassan',
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'country' => 'SY',
        'province' => 'damascus',
        'ethnicity' => 'arab',
        'hometown' => 'damascus',
        'email' => 'audit_test@gmail.com',
    ]);
});

// ───────────────────────────────────────────────
// Old/New Value Tracking
// ───────────────────────────────────────────────

it('captures old and new values when basic info changes', function (): void {
    test()->profileService->updateBasicInfo(test()->user, [
        'name' => 'Mohammed',
        'surname' => 'Ali',
    ]);

    $update = test()->user->profileUpdates()->latest('id')->first();

    expect($update->changes)->toBeArray()
        ->and($update->changes['name']['old'])->toBe('Ahmad')
        ->and($update->changes['name']['new'])->toBe('Mohammed')
        ->and($update->changes['surname']['old'])->toBe('Hassan')
        ->and($update->changes['surname']['new'])->toBe('Ali');
});

it('captures old and new values when address changes', function (): void {
    test()->profileService->updateAddress(test()->user, [
        'country' => 'TR',
        'province' => null,
    ], '10.0.0.1', 'Mozilla/5.0');

    $update = test()->user->profileUpdates()->latest('id')->first();

    expect($update->changes)->toBeArray()
        ->and($update->changes['country']['old'])->toBe('SY')
        ->and($update->changes['country']['new'])->toBe('TR');
});

it('captures old and new values when census data changes', function (): void {
    test()->user->update(['middle_name' => 'Khaled']);

    test()->profileService->updateCensusData(test()->user->fresh(), [
        'middle_name' => 'Youssef',
        'languages' => ['arabic', 'english'],
        'other_nationalities' => [],
    ]);

    $update = test()->user->profileUpdates()->latest('id')->first();

    expect($update->changes)->toBeArray()
        ->and($update->changes['middle_name']['old'])->toBe('Khaled')
        ->and($update->changes['middle_name']['new'])->toBe('Youssef');
});

it('only records fields that actually changed', function (): void {
    test()->profileService->updateBasicInfo(test()->user, [
        'name' => 'Ahmad',        // Same as original — should not appear
        'surname' => 'Mahmoud',   // Different — should appear
    ]);

    $update = test()->user->profileUpdates()->latest('id')->first();

    expect($update->changes)->toHaveKey('surname')
        ->and($update->changes)->not->toHaveKey('name');
});

// ───────────────────────────────────────────────
// Blocked Attempts
// ───────────────────────────────────────────────

it('logs blocked basic info update attempts', function (): void {
    $limit = config('e-syrians.verification.basic_info_updates_limit');

    for ($i = 0; $i < $limit; $i++) {
        test()->profileService->updateBasicInfo(test()->user, ['name' => "Name$i"]);
    }

    try {
        test()->profileService->updateBasicInfo(test()->user, ['name' => 'Blocked']);
    } catch (UpdateLimitReachedException) {
        // Expected
    }

    $blocked = test()->user->profileUpdates()
        ->where('blocked', true)
        ->first();

    expect($blocked)->not->toBeNull()
        ->and($blocked->block_reason)->toBe('limit_reached')
        ->and($blocked->changes)->toBeArray()
        ->and($blocked->change_type)->toBe(ProfileChangeTypeEnum::BasicData->value);
});

it('logs blocked address update attempts', function (): void {
    $limit = config('e-syrians.verification.country_updates_limit');

    $countries = ['TR', 'DE', 'FR'];
    for ($i = 0; $i < $limit; $i++) {
        test()->profileService->updateAddress(test()->user, [
            'country' => $countries[$i],
            'province' => null,
        ], '10.0.0.1', 'TestAgent');
    }

    try {
        test()->profileService->updateAddress(test()->user, [
            'country' => 'NL',
            'province' => null,
        ], '10.0.0.1', 'TestAgent');
    } catch (UpdateLimitReachedException) {
        // Expected
    }

    $blocked = test()->user->profileUpdates()
        ->where('blocked', true)
        ->first();

    expect($blocked)->not->toBeNull()
        ->and($blocked->block_reason)->toBe('limit_reached')
        ->and($blocked->change_type)->toBe(ProfileChangeTypeEnum::Address->value);
});

// ───────────────────────────────────────────────
// BigQuery Job Dispatch
// ───────────────────────────────────────────────

it('dispatches BigQuery job on basic info update', function (): void {
    test()->profileService->updateBasicInfo(test()->user, ['name' => 'New']);

    Queue::assertPushed(LogProfileChangeToBigQuery::class);
});

it('dispatches BigQuery job on address update', function (): void {
    test()->profileService->updateAddress(test()->user, [
        'country' => 'TR',
        'province' => null,
    ], '10.0.0.1', 'TestAgent');

    Queue::assertPushed(LogProfileChangeToBigQuery::class);
});

it('dispatches BigQuery job on census update', function (): void {
    test()->profileService->updateCensusData(test()->user, [
        'middle_name' => 'Test',
        'languages' => ['arabic'],
        'other_nationalities' => [],
    ]);

    Queue::assertPushed(LogProfileChangeToBigQuery::class);
});

it('dispatches BigQuery job for blocked attempts too', function (): void {
    $limit = config('e-syrians.verification.basic_info_updates_limit');

    for ($i = 0; $i < $limit; $i++) {
        test()->profileService->updateBasicInfo(test()->user, ['name' => "Name$i"]);
    }

    Queue::fake(); // Reset to only track the blocked attempt

    try {
        test()->profileService->updateBasicInfo(test()->user, ['name' => 'Blocked']);
    } catch (UpdateLimitReachedException) {
        // Expected
    }

    Queue::assertPushed(LogProfileChangeToBigQuery::class);
});

// ───────────────────────────────────────────────
// Request Source Detection
// ───────────────────────────────────────────────

it('records request source as web by default', function (): void {
    test()->profileService->updateBasicInfo(test()->user, ['name' => 'New']);

    $update = test()->user->profileUpdates()->latest('id')->first();

    expect($update->request_source)->toBe('web');
});
