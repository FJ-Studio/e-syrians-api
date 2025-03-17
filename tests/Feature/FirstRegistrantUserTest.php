<?php

use App\Models\User;
use App\Services\UserService;

static $verifiedUser;
static $unverifiedUser;

beforeEach(function () {
    $this->verifiedUser = User::factory()->create([
        'name' => 'Verified',
        'surname' => 'User',
        'email' => 'verified_user@gmail.com',
        'uuid' => '2d9b73f0-13d1-4d16-9914-8f0f21af6eec',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);

    $this->unverifiedUser = User::factory()->create([
        'name' => 'Unverified',
        'surname' => 'User',
        'email' => 'unverified_user@gmail.com',
        'uuid' => '6e0544ad-cd47-480f-9e33-d4fe047b6ab4',
        'verified_at' => null,
        'verification_reason' => null,
    ]);
});

it('First registrant user can verify new user', function () {
    $result = UserService::canUserAVerifyUserB($this->verifiedUser, $this->unverifiedUser);
    expect($result)->toBeArray();
    expect($result[0])->toBeTrue();
    expect($result[1])->toBe('');
});

it('New user user can not other users', function () {
    $result = UserService::canUserAVerifyUserB($this->unverifiedUser, $this->verifiedUser);
    expect($result)->toBeArray();
    expect($result[0])->toBeFalse();
    expect($result[1])->toBe('you_are_not_verified');
});

it('A user cannot verify himself', function () {
    $result = UserService::canUserAVerifyUserB($this->verifiedUser, $this->verifiedUser);
    expect($result)->toBeArray();
    expect($result[0])->toBefalse();
    expect($result[1])->toBe('you_cannot_verify_yourself');
});

it('A user cannot verify another user with uncompleted data', function () {
    $response = $this->postJson(route('users.verify', ['user' => $this->unverifiedUser->uuid]), [
        'uuid' => $this->unverifiedUser->uuid,
    ], [
        'Authorization' => 'Bearer '.$this->verifiedUser->createToken('test_token')->plainTextToken,
    ]);
    expect($response['messages'])->toContain('target_user_data_not_filled');
    $response->assertStatus(403);
});

it('A user cannot verify another user twice', function () {
    $this->unverifiedUser->update([
        'country' => 'US',
        'hometown' => 'damascus',
        'gender' => 'm',
        'birth_date' => '1990-01-01',
    ]);
    $response = $this->postJson(route('users.verify', ['user' => $this->unverifiedUser->uuid]), [
        'uuid' => $this->unverifiedUser->uuid,
    ], [
        'Authorization' => 'Bearer '.$this->verifiedUser->createToken('test_token')->plainTextToken,
    ]);

    $response2 = $this->postJson(route('users.verify', ['user' => $this->unverifiedUser->uuid]), [
        'uuid' => $this->unverifiedUser->uuid,
    ], [
        'Authorization' => 'Bearer '.$this->verifiedUser->createToken('test_token')->plainTextToken,
    ]);
    expect($response2['messages'])->toContain('you_have_already_verified_this_user');
    $response2->assertStatus(403);
});
