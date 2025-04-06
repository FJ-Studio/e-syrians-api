<?php

use App\Enums\CountryEnum;
use App\Enums\HometownEnum;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ───────────────────────────────────────────────
// Setup
// ───────────────────────────────────────────────

beforeEach(function () {
    $user = User::factory()->create([
        'name' => 'Unverified',
        'surname' => 'User',
        'email' => 'unverified_user@example.com',
        'uuid' => '6e0544ad-cd47-480f-9e33-d4fe047b6ab4',
        'verified_at' => null,
        'verification_reason' => null,
    ]);

    test()->user = $user;
});

// ───────────────────────────────────────────────
// User gets his profile data
// ───────────────────────────────────────────────

it('User gets profile data', function () {
    $result = $this->getJson(route('users.me'), authHeader(test()->user));
    $result->assertOk();
    $result->assertJsonStructure([
        'data' => [
            'uuid',
            'name',
            'surname',
            'avatar',
            'created_at',
            'birth_date',
            'hometown',
            'country',
            'facebook_link',
            'twitter_link',
            'linkedin_link',
            'instagram_link',
            'snapchat_link',
            'tiktok_link',
            'youtube_link',
            'pinterest_link',
            'twitch_link',
            'website',
            'github_link',
            'avatar',
            'country',
            'gender',
            'ethnicity',
            'verified_at',
            'record_id',
            'phone',
            'national_id',
            'middle_name',
            'email',
            'city',
            'address',
            'shelter',
            'education_level',
            'skills',
            'marital_status',
            'source_of_income',
            'estimated_monthly_income',
            'number_of_dependents',
            'health_status',
            'health_insurance',
            'easy_access_to_healthcare_services',
            'religious_affiliation',
            'other_nationalities',
            'communication',
            'more_info',
            'email_verified_at',
            'phone_verified_at',
            'verification_reason',
            'marked_as_fake_at',
            'languages',
            'other_nationalities',
            'roles',
            'permissions',
            'basic_info_updates',
            'received_verification_email',
            'account_verified_email',
            'city_inside_syria',
            'language',
        ],
    ]);

    $result->assertJson([
        'data' => [
            'name' => test()->user->name,
            'surname' => test()->user->surname,
            'email' => test()->user->email,
            'uuid' => test()->user->uuid,
            'verified_at' => null,
            'verification_reason' => null,
        ],
    ]);
});

// ───────────────────────────────────────────────
// User updates his profile
// ───────────────────────────────────────────────

it('User updates his profile correctly', function () {
    $result = $this->postJson(
        route('users.update.basic-info'),
        [
            'name' => 'Updated Name',
            'surname' => 'Updated Surname',
            'birth_date' => '1992-01-23',
            'gender' => 'f',
            'ethnicity' => 'assyrian',
            'hometown' => 'homs',
        ],
        authHeader(test()->user));

    // Check the response status
    $result->assertOk();
    // Check the database for the updated data
    $this->assertDatabaseHas('users', [
        'id' => test()->user->id,
        'name' => 'Updated Name',
        'surname' => 'Updated Surname',
        'birth_date' => '1992-01-23',
        'gender' => 'f',
        'ethnicity' => 'assyrian',
        'hometown' => 'homs',
    ]);

});

it('User updates his profile for limited times', function () {
    $limit = config('e-syrians.verification.basic_info_updates_limit');
    // consume the limit
    for ($i = 0; $i < $limit; $i++) {
        $result = $this->postJson(
            route('users.update.basic-info'),
            [
                'name' => 'Updated Name',
                'surname' => 'Updated Surname',
                'birth_date' => '1992-01-23',
                'gender' => 'f',
                'ethnicity' => 'assyrian',
                'hometown' => 'homs',
            ],
            authHeader(test()->user));

        $result->assertOk();
    }
    // Try to update again, should fail
    $result = $this->postJson(
        route('users.update.basic-info'),
        [
            'name' => 'Updated Name',
            'surname' => 'Updated Surname',
            'birth_date' => '1992-01-23',
            'gender' => 'f',
            'ethnicity' => 'assyrian',
            'hometown' => 'homs',
        ],
        authHeader(test()->user));
    // Check the response status and messages
    $result->assertStatus(403);
    expect($result['messages'])->toContain('basic_info_updates_limit_reached');
});

it('User can update his social media links', function () {
    $result = $this->postJson(
        route('users.update.social'),
        [
            'facebook_link' => 'https://www.facebook.com/updated_user',
            'twitter_link' => 'https://www.twitter.com/updated_user',
            'linkedin_link' => 'https://www.linkedin.com/in/updated_user',
            'instagram_link' => 'https://www.instagram.com/updated_user',
            'snapchat_link' => 'https://www.snapchat.com/updated_user',
            'tiktok_link' => 'https://www.tiktok.com/updated_user',
            'youtube_link' => 'https://www.youtube.com/updated_user',
            'pinterest_link' => 'https://www.pinterest.com/updated_user',
            'twitch_link' => 'https://www.twitch.com/updated_user',
            'github_link' => 'https://www.github.com/updated_user',
            'website' => 'https://www.fjobeir.com',
        ],
        authHeader(test()->user)
    );
    $result->assertOk();
    $this->assertDatabaseHas('users', [
        'id' => test()->user->id,
        'facebook_link' => 'https://www.facebook.com/updated_user',
        'twitter_link' => 'https://www.twitter.com/updated_user',
        'linkedin_link' => 'https://www.linkedin.com/in/updated_user',
        'instagram_link' => 'https://www.instagram.com/updated_user',
        'snapchat_link' => 'https://www.snapchat.com/updated_user',
        'tiktok_link' => 'https://www.tiktok.com/updated_user',
        'youtube_link' => 'https://www.youtube.com/updated_user',
        'pinterest_link' => 'https://www.pinterest.com/updated_user',
        'twitch_link' => 'https://www.twitch.com/updated_user',
        'github_link' => 'https://www.github.com/updated_user',
        'website' => 'https://www.fjobeir.com',
    ]);
});

it('updates the user avatar and stores it in S3', function () {
    Storage::fake('s3'); // Fakes S3 so nothing is actually uploaded

    $file = UploadedFile::fake()->image('avatar.jpg');

    $response = $this->actingAs(test()->user)->postJson(
        route('users.update.avatar'),
        ['avatar' => $file]
    );

    $fileName = 'avatars/'.test()->user->uuid.'.'.$file->getClientOriginalExtension();

    $response->assertOk();
    $response->assertJsonPath('data.url', Storage::disk('s3')->url($fileName));

    Storage::disk('s3')->assertExists($fileName);

    expect(test()->user->fresh()->avatar)->toBe($fileName);
});

it('fails when avatar is missing', function () {
    $response = $this->actingAs(test()->user)->postJson(route('users.update.avatar'), []);
    $response->assertStatus(422);
    expect($response['messages'])->toHaveKey('avatar');
});

it('fails when avatar is not an image', function () {

    $file = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->actingAs(test()->user)->postJson(route('users.update.avatar'), [
        'avatar' => $file,
    ]);

    $response->assertStatus(422);
    expect($response['messages'])->toHaveKey('avatar');
});

it('fails when avatar exceeds 500KB', function () {
    $file = UploadedFile::fake()->image('big-avatar.jpg')->size(600);
    $response = $this->actingAs(test()->user)->postJson(route('users.update.avatar'), [
        'avatar' => $file,
    ]);
    $response->assertStatus(422);
    expect($response['messages'])->toHaveKey('avatar');
});

it('fails when avatar image exceeds max dimensions', function () {
    $file = UploadedFile::fake()->image('large.jpg', 1000, 1000); // Exceeds 800x800

    $response = $this->actingAs(test()->user)->postJson(route('users.update.avatar'), [
        'avatar' => $file,
    ]);

    $response->assertStatus(422);
    expect($response['messages'])->toHaveKey('avatar');
});

it('allows user to update to another country', function () {
    $response = $this->postJson(
        route('users.update.address'),
        [
            'country' => CountryEnum::US->value,
            'city_inside_syria' => null,
        ],
        authHeader(test()->user)
    );

    $response->assertOk();
    $this->assertDatabaseHas('users', [
        'id' => test()->user->id,
        'country' => CountryEnum::US->value,
    ]);
});

// ✅ 2. Can update to SY with valid hometown
it('allows update to SY with valid hometown', function () {
    $response = $this->postJson(
        route('users.update.address'),
        [
            'country' => CountryEnum::SY->value,
            'city_inside_syria' => HometownEnum::Damascus->value,
        ],
        authHeader(test()->user)
    );

    $response->assertOk();
    $this->assertDatabaseHas('users', [
        'id' => test()->user->id,
        'country' => CountryEnum::SY->value,
        'city_inside_syria' => HometownEnum::Damascus->value,
    ]);
});

// ❌ 3. Missing city_inside_syria when country is SY
it('fails when updating to SY without hometown', function () {
    $response = $this->postJson(
        route('users.update.address'),
        [
            'country' => CountryEnum::SY->value,
        ],
        authHeader(test()->user)
    );

    $response->assertStatus(422);
    expect($response['messages'])->toHaveKey('city_inside_syria');
});

// ❌ 4. Fails when update count is exceeded
it('prevents update when country update limit is reached', function () {
    $limit = config('e-syrians.verification.country_updates_limit');

    // Consume the limit
    for ($i = 0; $i < $limit; $i++) {
        $response = $this->postJson(
            route('users.update.address'),
            [
                'country' => CountryEnum::SY->value,
                'city_inside_syria' => HometownEnum::Damascus->value,
            ],
            authHeader(test()->user)
        );

        $response->assertOk();
    }
    // Try to update again, should fail
    $response = $this->postJson(
        route('users.update.address'),
        [
            'country' => CountryEnum::US->value,
        ],
        authHeader(test()->user)
    );

    $response->assertStatus(403);
    expect($response['messages'][0])->toBe('country_updates_limit_reached');
});

// ❌ 5. Invalid country / city
it('fails with invalid country or city', function () {
    $response = $this->postJson(
        route('users.update.address'),
        [
            'country' => 'INVALID',
            'city_inside_syria' => 'Nowhere',
        ],
        authHeader(test()->user)
    );

    $response->assertStatus(422);
    expect($response['messages'])->toHaveKey('country');
    expect($response['messages'])->toHaveKey('city_inside_syria');
});

// Census Data being updated correctly

it('a user can update the rest of census data', function () {
    $response = $this->postJson(
        route('users.update.census'),
        [
            'middle_name' => 'Middle',
            'city' => 'City',
            'address' => 'Address',
            'shelter' => '0',
            'education_level' => 'postgraduate',
            'skills' => 'coding, singing, writing',
            'marital_status' => 'single',
            'source_of_income' => 'stable-job',
            'estimated_monthly_income' => 1000,
            'number_of_dependents' => 2,
            'health_status' => 'good',
            'health_insurance' => true,
            'easy_access_to_healthcare_services' => true,
            'religious_affiliation' => 'sunni',
            'other_nationalities' => ['TR', 'US'],
            'communication' => 'Email or phone',
            'more_info' => 'Thank you for your help!',
            'languages' => ['english', 'arabic'],
        ],
        authHeader(test()->user)
    );
    $response->assertOk();
    // assert those values in the database
    $expected = [
        'id' => test()->user->id,
        'middle_name' => 'Middle',
        'city' => 'City',
        'shelter' => 0,
        'education_level' => 'postgraduate',
        'skills' => 'coding, singing, writing',
        'marital_status' => 'single',
        'source_of_income' => 'stable-job',
        'estimated_monthly_income' => 1000,
        'number_of_dependents' => 2,
        'health_status' => 'good',
        'health_insurance' => true,
        'easy_access_to_healthcare_services' => true,
        'religious_affiliation' => 'sunni',
        'other_nationalities' => 'TR,US',
        'communication' => 'Email or phone',
        'more_info' => 'Thank you for your help!',
        'languages' => 'english,arabic',
    ];
    foreach ($expected as $key => $value) {
        $this->assertDatabaseHas('users', [
            'id' => test()->user->id,
            $key => $value,
        ]);
    }
    // assert the response
});
