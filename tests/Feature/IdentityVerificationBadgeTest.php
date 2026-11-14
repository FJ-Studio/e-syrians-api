<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use Illuminate\Support\Str;
use App\Models\FeatureRequest;
use App\Models\IdentitySandboxCredit;
use Illuminate\Support\Facades\Cache;
use App\Models\IdentityVerificationCredit;
use App\Contracts\FileUploadServiceContract;

it('publishes only the badge for a live approval without changing community verification', function (): void {
    $owner = User::factory()->create(['verified_at' => null, 'verification_reason' => null]);
    $credit = IdentityVerificationCredit::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'owner_id' => $owner->id,
        'source' => IdentityVerificationCredit::SOURCE_PURCHASE,
        'status' => 'approved',
        'provider_session_id' => (string) Str::uuid(),
    ]);

    $this->getJson('/users/verify/'.$owner->uuid)->assertOk()
        ->assertJsonPath('data.verification_badge', 'identity')
        ->assertJsonMissingPath('data.id_verification_approved')
        ->assertJsonMissingPath('data.provider_session_id')
        ->assertJsonMissingPath('data.approved_identity_checks_exists');

    $this->getJson('/users/me', authHeader($owner))->assertOk()
        ->assertJsonPath('data.id_verification_approved', true)
        ->assertJsonPath('data.verification_badge', 'identity')
        ->assertJsonPath('data.community_verified_at', null)
        ->assertJsonMissingPath('data.verified_at')
        ->assertJsonMissingPath('data.provider_session_id');

    $credit->forceFill(['status' => 'revoked'])->save();
    $this->getJson('/users/verify/'.$owner->uuid)->assertOk()
        ->assertJsonPath('data.verification_badge', null);
    $this->getJson('/users/me', authHeader($owner))->assertOk()
        ->assertJsonPath('data.id_verification_approved', false);
});

it('requires community verification for participation even with approved live ID and a citizen role', function (): void {
    $member = User::factory()->create(['verified_at' => null]);
    $member->assignRole('citizen');
    IdentityVerificationCredit::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'owner_id' => $member->id,
        'source' => IdentityVerificationCredit::SOURCE_PURCHASE,
        'status' => 'approved',
        'provider_session_id' => (string) Str::uuid(),
    ]);
    $headers = authHeader($member);

    foreach (['/polls', '/polls/vote', '/polls/react', '/feature-requests', '/feature-requests/vote'] as $path) {
        $this->postJson($path, [], $headers)->assertForbidden()
            ->assertJsonPath('messages.0', 'you_are_not_verified');
    }
    $this->getJson('/users/audiences', $headers)->assertForbidden()
        ->assertJsonPath('messages.0', 'you_are_not_verified');

    $poll = Poll::forceCreate([
        'question' => 'Previously published poll?',
        'created_by' => $member->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'max_selections' => 1,
        'reveal_results' => 'before-voting',
    ]);
    $this->patchJson('/polls/'.$poll->id, ['question' => 'Edited poll?'], $headers)->assertForbidden()
        ->assertJsonPath('messages.0', 'you_are_not_verified');
    $this->postJson('/polls/status/'.$poll->id, [], $headers)->assertOk();
    $this->postJson('/polls/status/'.$poll->id, [], $headers)->assertForbidden()
        ->assertJsonPath('messages.0', 'you_are_not_verified');
});

it('does not give the live badge for sandbox results or a non-approved live check', function (): void {
    $owner = User::factory()->create(['verified_at' => now()]);
    IdentitySandboxCredit::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'issued_to_user_id' => $owner->id,
        'owner_id' => $owner->id,
        'source' => IdentitySandboxCredit::SOURCE_SELF_CLAIM,
        'status' => 'approved',
        'provider_session_id' => (string) Str::uuid(),
    ]);
    IdentityVerificationCredit::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'owner_id' => $owner->id,
        'source' => IdentityVerificationCredit::SOURCE_PURCHASE,
        'status' => 'in_review',
        'provider_session_id' => (string) Str::uuid(),
    ]);

    $this->getJson('/users/verify/'.$owner->uuid)->assertOk()
        ->assertJsonPath('data.verification_badge', 'community');
    $this->getJson('/users/me', authHeader($owner))->assertOk()
        ->assertJsonPath('data.id_verification_approved', false)
        ->assertJsonStructure(['data' => ['community_verified_at']])
        ->assertJsonPath('data.verification_badge', 'community');
});

it('includes the combined badge on poll and feature-request authors and first registrants', function (): void {
    $this->mock(FileUploadServiceContract::class)->shouldReceive('temporaryUrl')->andReturn('https://example.test/avatar.png');
    Cache::forget('verified_first_registrant_uuids');
    $owner = User::factory()->create([
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'avatar' => 'avatars/test.png',
        'website' => 'https://example.test',
    ]);
    IdentityVerificationCredit::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'owner_id' => $owner->id,
        'source' => IdentityVerificationCredit::SOURCE_PURCHASE,
        'status' => 'approved',
        'provider_session_id' => (string) Str::uuid(),
    ]);
    $poll = Poll::forceCreate([
        'question' => 'Badge test poll?',
        'created_by' => $owner->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'max_selections' => 1,
        'reveal_results' => 'before-voting',
    ]);
    $feature = FeatureRequest::forceCreate([
        'title' => 'Badge test request',
        'description' => 'Show the verification badge by the author’s name.',
        'created_by' => $owner->id,
    ]);

    foreach ([
        ['/users/first', 'data.0'],
        ['/polls', 'data.polls.0.user'],
        ['/polls/'.$poll->id, 'data.user'],
        ['/feature-requests', 'data.feature_requests.0.user'],
        ['/feature-requests/'.$feature->id, 'data.user'],
    ] as [$url, $path]) {
        $this->getJson($url)->assertOk()
            ->assertJsonPath($path.'.uuid', (string) $owner->uuid)
            ->assertJsonPath($path.'.verification_badge', 'both')
            ->assertJsonMissingPath($path.'.id_verification_approved')
            ->assertJsonMissingPath($path.'.provider_session_id');
    }
    Cache::forget('verified_first_registrant_uuids');
});
