<?php

use App\Models\Poll;
use App\Models\User;
use App\Models\PollOption;
use App\Models\PollAudienceRule;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    test()->creator = User::factory()->create([
        'email' => 'creator@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'TR',
        'ethnicity' => 'arab',
    ]);
    test()->creator->assignRole('citizen');

    test()->otherUser = User::factory()->create([
        'email' => 'other@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'f',
        'birth_date' => '1995-01-01',
        'hometown' => 'aleppo',
        'country' => 'DE',
        'ethnicity' => 'arab',
    ]);
    test()->otherUser->assignRole('citizen');
});

// ───────────────────────────────────────────────
// Validation
// ───────────────────────────────────────────────

it('returns 422 when poll_id is missing', function (): void {
    $response = $this->getJson('/polls/audience');

    $response->assertStatus(422);
});

it('returns 422 when poll_id does not exist', function (): void {
    $response = $this->getJson('/polls/audience?poll_id=99999');

    $response->assertStatus(422);
});

// ───────────────────────────────────────────────
// Demographic audience — accessible to everyone
// ───────────────────────────────────────────────

it('returns demographic audience criteria for a guest', function (): void {
    $poll = createAudienceEndpointDemographicPoll(test()->creator);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}");

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveKeys(['gender', 'age_range', 'country', 'religious_affiliation', 'hometown', 'ethnicity', 'province']);
    expect($data['gender'])->toBe(['m']);
    expect($data['country'])->toBe(['TR']);
    expect($data['age_range']['min'])->toBe(18);
    expect($data['age_range']['max'])->toBe(50);
});

it('returns demographic audience criteria for an authenticated non-creator', function (): void {
    $poll = createAudienceEndpointDemographicPoll(test()->creator);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}", authHeader(test()->otherUser));

    $response->assertOk();
    $data = $response->json('data');
    expect($data['gender'])->toBe(['m']);
    expect($data['country'])->toBe(['TR']);
});

it('returns demographic audience criteria for the creator', function (): void {
    $poll = createAudienceEndpointDemographicPoll(test()->creator);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}", authHeader(test()->creator));

    $response->assertOk();
    $data = $response->json('data');
    expect($data['gender'])->toBe(['m']);
    expect($data['country'])->toBe(['TR']);
});

it('returns default age range when no age rules exist', function (): void {
    $poll = createAudienceEndpointPollWithRules(test()->creator, [
        ['criterion' => 'gender', 'value' => 'f'],
    ]);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}");

    $response->assertOk();
    $data = $response->json('data');
    expect($data['age_range']['min'])->toBe(13);
    expect($data['age_range']['max'])->toBe(120);
});

it('returns empty arrays for criteria with no rules', function (): void {
    $poll = createAudienceEndpointPollWithRules(test()->creator, [
        ['criterion' => 'gender', 'value' => 'm'],
    ]);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}");

    $response->assertOk();
    $data = $response->json('data');
    expect($data['country'])->toBe([]);
    expect($data['province'])->toBe([]);
    expect($data['hometown'])->toBe([]);
    expect($data['ethnicity'])->toBe([]);
    expect($data['religious_affiliation'])->toBe([]);
});

it('returns multiple values for the same criterion', function (): void {
    $poll = createAudienceEndpointPollWithRules(test()->creator, [
        ['criterion' => 'country', 'value' => 'TR'],
        ['criterion' => 'country', 'value' => 'DE'],
        ['criterion' => 'country', 'value' => 'SY'],
    ]);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}");

    $response->assertOk();
    $data = $response->json('data');
    expect($data['country'])->toHaveCount(3);
    expect($data['country'])->toContain('TR', 'DE', 'SY');
});

it('returns province rules correctly', function (): void {
    $poll = createAudienceEndpointPollWithRules(test()->creator, [
        ['criterion' => 'province', 'value' => 'damascus'],
        ['criterion' => 'province', 'value' => 'aleppo'],
    ]);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}");

    $response->assertOk();
    $data = $response->json('data');
    expect($data['province'])->toHaveCount(2);
    expect($data['province'])->toContain('damascus', 'aleppo');
});

// ───────────────────────────────────────────────
// Poll with no audience rules
// ───────────────────────────────────────────────

it('returns a full demographic structure with empty arrays when poll has no rules', function (): void {
    $poll = createAudienceEndpointPollWithRules(test()->creator, []);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}");

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveKeys(['gender', 'age_range', 'country', 'religious_affiliation', 'hometown', 'ethnicity', 'province']);
    expect($data['gender'])->toBe([]);
    expect($data['country'])->toBe([]);
});

// ───────────────────────────────────────────────
// Allowed-voters audience — hidden from non-creators
// ───────────────────────────────────────────────

it('returns allowed_voters list to the creator', function (): void {
    $poll = createAudienceEndpointAllowedVotersPoll(test()->creator, ['user1@test.com', 'user2@test.com', 'user3@test.com']);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}", authHeader(test()->creator));

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveKey('allowed_voters');
    expect($data['allowed_voters'])->toHaveCount(3);
    expect($data['allowed_voters'])->toContain('user1@test.com', 'user2@test.com', 'user3@test.com');
});

it('returns empty allowed_voters array to a non-creator', function (): void {
    $poll = createAudienceEndpointAllowedVotersPoll(test()->creator, ['user1@test.com', 'user2@test.com']);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}", authHeader(test()->otherUser));

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveKey('allowed_voters');
    expect($data['allowed_voters'])->toBe([]);
});

it('returns empty allowed_voters array to a guest', function (): void {
    $poll = createAudienceEndpointAllowedVotersPoll(test()->creator, ['user1@test.com']);

    $response = $this->getJson("/polls/audience?poll_id={$poll->id}");

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveKey('allowed_voters');
    expect($data['allowed_voters'])->toBe([]);
});

// ───────────────────────────────────────────────
// Caching
// ───────────────────────────────────────────────

it('caches the audience data after the first request', function (): void {
    $poll = createAudienceEndpointDemographicPoll(test()->creator);

    $cacheKey = "poll:{$poll->id}:audience";
    expect(Cache::has($cacheKey))->toBeFalse();

    $this->getJson("/polls/audience?poll_id={$poll->id}")->assertOk();

    expect(Cache::has($cacheKey))->toBeTrue();
});

it('serves cached audience data on subsequent requests', function (): void {
    $poll = createAudienceEndpointDemographicPoll(test()->creator);

    // First request populates cache
    $first = $this->getJson("/polls/audience?poll_id={$poll->id}");
    $first->assertOk();

    // Delete the audience rules from the DB — cached data should still be returned
    PollAudienceRule::where('poll_id', $poll->id)->delete();

    $second = $this->getJson("/polls/audience?poll_id={$poll->id}");
    $second->assertOk();

    // The cached response should still contain the original rules
    expect($second->json('data.gender'))->toBe(['m']);
    expect($second->json('data.country'))->toBe(['TR']);
});

it('does not leak allowed_voters from cache to a non-creator', function (): void {
    $poll = createAudienceEndpointAllowedVotersPoll(test()->creator, ['secret@test.com']);

    // Creator populates the cache (with full allowed_voters)
    $creatorResponse = $this->getJson("/polls/audience?poll_id={$poll->id}", authHeader(test()->creator));
    $creatorResponse->assertOk();
    expect($creatorResponse->json('data.allowed_voters'))->toHaveCount(1);

    // Reset the resolved guard so the next request picks up the new token.
    auth('sanctum')->forgetUser();

    // Non-creator should still get empty allowed_voters despite cache having the full list
    $otherResponse = $this->getJson("/polls/audience?poll_id={$poll->id}", authHeader(test()->otherUser));
    $otherResponse->assertOk();
    expect($otherResponse->json('data.allowed_voters'))->toBe([]);
});

// ───────────────────────────────────────────────
// Helpers
// ───────────────────────────────────────────────

function createAudienceEndpointDemographicPoll(User $user): Poll
{
    $poll = Poll::forceCreate([
        'question' => 'Demographic audience poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => $user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience_only' => false,
        'is_private' => false,
    ]);

    $now = now();
    PollAudienceRule::insert([
        ['poll_id' => $poll->id, 'criterion' => 'gender', 'value' => 'm', 'created_at' => $now, 'updated_at' => $now],
        ['poll_id' => $poll->id, 'criterion' => 'country', 'value' => 'TR', 'created_at' => $now, 'updated_at' => $now],
        ['poll_id' => $poll->id, 'criterion' => 'age_min', 'value' => '18', 'created_at' => $now, 'updated_at' => $now],
        ['poll_id' => $poll->id, 'criterion' => 'age_max', 'value' => '50', 'created_at' => $now, 'updated_at' => $now],
    ]);

    PollOption::insert([
        ['poll_id' => $poll->id, 'option_text' => 'Yes', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
        ['poll_id' => $poll->id, 'option_text' => 'No', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $poll->fresh()->load(['options', 'audienceRules']);
}

function createAudienceEndpointAllowedVotersPoll(User $user, array $voters): Poll
{
    $poll = Poll::forceCreate([
        'question' => 'Allowed voters poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => $user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience_only' => true,
        'is_private' => false,
    ]);

    $now = now();
    $rules = array_map(fn (string $voter) => [
        'poll_id' => $poll->id,
        'criterion' => 'allowed_voter',
        'value' => $voter,
        'created_at' => $now,
        'updated_at' => $now,
    ], $voters);
    PollAudienceRule::insert($rules);

    PollOption::insert([
        ['poll_id' => $poll->id, 'option_text' => 'Yes', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
        ['poll_id' => $poll->id, 'option_text' => 'No', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $poll->fresh()->load(['options', 'audienceRules']);
}

function createAudienceEndpointPollWithRules(User $user, array $rules): Poll
{
    $poll = Poll::forceCreate([
        'question' => 'Custom rules poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => $user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience_only' => false,
        'is_private' => false,
    ]);

    $now = now();
    if (count($rules) > 0) {
        PollAudienceRule::insert(array_map(fn (array $rule) => [
            'poll_id' => $poll->id,
            'criterion' => $rule['criterion'],
            'value' => $rule['value'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rules));
    }

    PollOption::insert([
        ['poll_id' => $poll->id, 'option_text' => 'Yes', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
        ['poll_id' => $poll->id, 'option_text' => 'No', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $poll->fresh()->load(['options', 'audienceRules']);
}
