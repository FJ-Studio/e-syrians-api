<?php

use App\Enums\ProfileChangeTypeEnum;
use App\Exceptions\UpdateLimitReachedException;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->profileService = app(ProfileService::class);
    test()->user = User::factory()->create([
        'name' => 'Original',
        'surname' => 'Name',
        'email' => 'ps_user@example.com',
    ]);
});

// ───────────────────────────────────────────────
// Update Basic Info
// ───────────────────────────────────────────────

it('updates basic info and creates profile update record', function () {
    test()->profileService->updateBasicInfo(test()->user, [
        'name' => 'Updated',
        'surname' => 'Surname',
    ]);

    $this->assertDatabaseHas('users', [
        'id' => test()->user->id,
        'name' => 'Updated',
        'surname' => 'Surname',
    ]);

    $this->assertDatabaseHas('profile_updates', [
        'user_id' => test()->user->id,
        'change_type' => ProfileChangeTypeEnum::BasicData->value,
    ]);
});

it('cancels active verifications when basic info changes', function () {
    // Create a verification
    test()->user->verifications()->create([
        'user_id' => test()->user->id,
        'verifier_id' => test()->user->id,
    ]);

    test()->profileService->updateBasicInfo(test()->user, [
        'name' => 'Changed',
    ]);

    $this->assertDatabaseHas('user_verifications', [
        'user_id' => test()->user->id,
        'cancelation_payload->reason' => 'user_updated_basic_info',
    ]);
});

it('marks user as unverified after basic info update', function () {
    test()->user->update(['verified_at' => now()]);

    test()->profileService->updateBasicInfo(test()->user, ['name' => 'Changed']);

    expect(test()->user->fresh()->verified_at)->toBeNull();
});

it('throws UpdateLimitReachedException when limit is reached', function () {
    $limit = config('e-syrians.verification.basic_info_updates_limit');

    for ($i = 0; $i < $limit; $i++) {
        test()->profileService->updateBasicInfo(test()->user, ['name' => "Name$i"]);
    }

    test()->profileService->updateBasicInfo(test()->user, ['name' => 'OneMore']);
})->throws(UpdateLimitReachedException::class);

// ───────────────────────────────────────────────
// Update Social Links
// ───────────────────────────────────────────────

it('updates social media links', function () {
    test()->profileService->updateSocialLinks(test()->user, [
        'facebook_link' => 'https://facebook.com/test',
        'twitter_link' => 'https://twitter.com/test',
    ]);

    $this->assertDatabaseHas('users', [
        'id' => test()->user->id,
        'facebook_link' => 'https://facebook.com/test',
        'twitter_link' => 'https://twitter.com/test',
    ]);
});

// ───────────────────────────────────────────────
// Update Avatar
// ───────────────────────────────────────────────

it('uploads avatar and returns URL', function () {
    Storage::fake('s3');

    $file = UploadedFile::fake()->image('avatar.jpg');
    $url = test()->profileService->updateAvatar(test()->user, $file);

    expect($url)->toBeString();
    expect(test()->user->fresh()->avatar)->toContain('avatars/');
    Storage::disk('s3')->assertExists(test()->user->fresh()->avatar);
});

it('rejects non-image file types', function () {
    Storage::fake('s3');

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    expect(fn () => test()->profileService->updateAvatar(test()->user, $file))
        ->toThrow(\InvalidArgumentException::class, 'invalid_file_type');
});

it('updates avatar path when uploading new avatar', function () {
    Storage::fake('s3');

    // Upload first avatar
    $oldFile = UploadedFile::fake()->image('old-avatar.jpg');
    test()->profileService->updateAvatar(test()->user, $oldFile);

    $oldPath = test()->user->fresh()->avatar;
    expect($oldPath)->toContain('avatars/');
    expect($oldPath)->toEndWith('.jpg');

    // Upload second avatar with different extension
    $newFile = UploadedFile::fake()->image('new-avatar.png');
    $freshUser = test()->user->fresh();
    test()->profileService->updateAvatar($freshUser, $newFile);

    $newPath = test()->user->fresh()->avatar;
    expect($newPath)->toContain('avatars/');
    expect($newPath)->toEndWith('.png');
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('s3')->assertExists($newPath);
});

// ───────────────────────────────────────────────
// Update Census Data
// ───────────────────────────────────────────────

it('converts arrays to comma-separated strings for census data', function () {
    test()->profileService->updateCensusData(test()->user, [
        'languages' => ['arabic', 'english'],
        'other_nationalities' => ['TR', 'US'],
        'middle_name' => 'Test',
    ]);

    $user = test()->user->fresh();
    expect($user->languages)->toBe('arabic,english');
    expect($user->other_nationalities)->toBe('TR,US');
    expect($user->middle_name)->toBe('Test');
});

// ───────────────────────────────────────────────
// Update Address
// ───────────────────────────────────────────────

it('updates address and records profile change with IP', function () {
    test()->profileService->updateAddress(test()->user, [
        'country' => 'TR',
        'city_inside_syria' => null,
    ], '192.168.1.1', 'TestAgent/1.0');

    $this->assertDatabaseHas('users', [
        'id' => test()->user->id,
        'country' => 'TR',
    ]);

    $this->assertDatabaseHas('profile_updates', [
        'user_id' => test()->user->id,
        'change_type' => ProfileChangeTypeEnum::Address->value,
        'ip_address' => '192.168.1.1',
        'user_agent' => 'TestAgent/1.0',
    ]);
});

// ───────────────────────────────────────────────
// Change Email
// ───────────────────────────────────────────────

it('changes email and resets verification status', function () {
    test()->user->update(['email_verified_at' => now()]);

    test()->profileService->changeEmail(test()->user, 'new-email@example.com');

    $user = test()->user->fresh();
    expect($user->email)->toBe('new-email@example.com');
    expect($user->email_verified_at)->toBeNull();
});

// ───────────────────────────────────────────────
// Update Notifications
// ───────────────────────────────────────────────

it('updates notification preferences', function () {
    test()->profileService->updateNotifications(test()->user, [
        'received_verification_email' => true,
        'account_verified_email' => false,
    ]);

    $user = test()->user->fresh();
    expect($user->received_verification_email)->toBeTruthy();
    expect($user->account_verified_email)->toBeFalsy();
});

// ───────────────────────────────────────────────
// Update Language
// ───────────────────────────────────────────────

it('updates preferred language', function () {
    test()->profileService->updateLanguage(test()->user, 'ar');

    expect(test()->user->fresh()->language)->toBe('ar');
});
