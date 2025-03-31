<?php

use App\Models\User;

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
//

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
