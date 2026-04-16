<?php

use App\Models\Poll;
use App\Models\User;
use App\Models\PollOption;
use App\Models\PollAudienceRule;

beforeEach(function (): void {
    // Creator user — male, Syrian in Turkey
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

    // Audience-matching user — male, Syrian in Turkey
    test()->audienceUser = User::factory()->create([
        'email' => 'audience@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'm',
        'birth_date' => '1995-01-01',
        'hometown' => 'damascus',
        'country' => 'TR',
        'ethnicity' => 'arab',
    ]);
    test()->audienceUser->assignRole('citizen');

    // Non-audience user — female, in Germany
    test()->outsider = User::factory()->create([
        'email' => 'outsider@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'f',
        'birth_date' => '1985-01-01',
        'hometown' => 'aleppo',
        'country' => 'DE',
        'ethnicity' => 'arab',
    ]);
    test()->outsider->assignRole('citizen');
});

// ───────────────────────────────────────────────
// Index — audience_only polls hidden from non-audience
// ───────────────────────────────────────────────

it('hides audience_only polls from guests in the index', function (): void {
    createAudienceOnlyPoll(test()->creator);
    createPublicPoll(test()->creator);

    $response = $this->getJson('/polls');

    $response->assertOk();
    $data = $response->json('data');
    expect($data['polls'])->toHaveCount(1);
    expect($data['audience_only_count'])->toBeGreaterThanOrEqual(1);
});

it('shows audience_only polls to matching users in the index', function (): void {
    createAudienceOnlyPoll(test()->creator);
    createPublicPoll(test()->creator);

    $response = $this->getJson('/polls', authHeader(test()->audienceUser));

    $response->assertOk();
    $data = $response->json('data');
    expect($data['polls'])->toHaveCount(2);
});

it('hides audience_only polls from non-matching users in the index', function (): void {
    createAudienceOnlyPoll(test()->creator);
    createPublicPoll(test()->creator);

    $response = $this->getJson('/polls', authHeader(test()->outsider));

    $response->assertOk();
    $data = $response->json('data');
    expect($data['polls'])->toHaveCount(1);
    expect($data['audience_only_count'])->toBe(1);
});

it('always shows audience_only polls to the creator in the index', function (): void {
    createAudienceOnlyPoll(test()->creator);

    $response = $this->getJson('/polls', authHeader(test()->creator));

    $response->assertOk();
    $data = $response->json('data');
    expect($data['polls'])->toHaveCount(1);
});

it('returns audience_only_count in the index response', function (): void {
    createAudienceOnlyPoll(test()->creator);

    $response = $this->getJson('/polls');

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['polls', 'audience_only_count']]);
});

// ───────────────────────────────────────────────
// Show — audience_only polls restricted for non-audience
// ───────────────────────────────────────────────

it('returns 403 for audience_only poll when guest accesses it', function (): void {
    $poll = createAudienceOnlyPoll(test()->creator);

    $response = $this->getJson("/polls/{$poll->id}");

    $response->assertStatus(403);
});

it('returns 403 for audience_only poll when non-audience user accesses it', function (): void {
    $poll = createAudienceOnlyPoll(test()->creator);

    $response = $this->getJson("/polls/{$poll->id}", authHeader(test()->outsider));

    $response->assertStatus(403);
});

it('shows audience_only poll to matching audience user', function (): void {
    $poll = createAudienceOnlyPoll(test()->creator);

    $response = $this->getJson("/polls/{$poll->id}", authHeader(test()->audienceUser));

    $response->assertOk();
});

it('shows audience_only poll to the creator', function (): void {
    $poll = createAudienceOnlyPoll(test()->creator);

    $response = $this->getJson("/polls/{$poll->id}", authHeader(test()->creator));

    $response->assertOk();
});

// ───────────────────────────────────────────────
// Store — creating audience_only polls
// ───────────────────────────────────────────────

it('creates a poll with audience_only enabled', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Audience only poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience_only' => true,
        'options' => ['Yes', 'No'],
        'gender' => ['m'],
        'country' => ['TR'],
    ], authHeader(test()->creator));

    $response->assertOk();
    $this->assertDatabaseHas('polls', [
        'question' => 'Audience only poll?',
        'audience_only' => true,
    ]);
});

it('defaults audience_only to false when not provided', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Regular poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
    ], authHeader(test()->creator));

    $response->assertOk();
    $this->assertDatabaseHas('polls', [
        'question' => 'Regular poll?',
        'audience_only' => false,
    ]);
});

// ───────────────────────────────────────────────
// Non-audience_only polls remain fully visible
// ───────────────────────────────────────────────

it('shows non-audience_only polls to everyone', function (): void {
    $poll = createPublicPoll(test()->creator);

    // Guest
    $this->getJson("/polls/{$poll->id}")->assertOk();

    // Non-audience user
    $this->getJson("/polls/{$poll->id}", authHeader(test()->outsider))->assertOk();

    // Audience user
    $this->getJson("/polls/{$poll->id}", authHeader(test()->audienceUser))->assertOk();
});

// ───────────────────────────────────────────────
// Resource payload — is_in_audience / audience_failures / audience visibility
// ───────────────────────────────────────────────

it('returns is_in_audience=true and empty failures for a poll with no rules', function (): void {
    $poll = createPublicPoll(test()->creator);

    $response = $this->getJson("/polls/{$poll->id}", authHeader(test()->audienceUser));

    $response->assertOk();
    expect($response->json('data.is_in_audience'))->toBeTrue();
    expect($response->json('data.audience_failures'))->toBe([]);
});

it('returns is_in_audience=true and empty failures for a matching user', function (): void {
    $poll = createAudienceOnlyPoll(test()->creator);

    $response = $this->getJson("/polls/{$poll->id}", authHeader(test()->audienceUser));

    $response->assertOk();
    expect($response->json('data.is_in_audience'))->toBeTrue();
    expect($response->json('data.audience_failures'))->toBe([]);
});

it('returns is_in_audience=true for the creator even when not matching the audience', function (): void {
    // The creator matches but let's verify the creator-shortcut path works regardless.
    $poll = createAudienceOnlyPoll(test()->creator);

    // Use a criterion that wouldn't match the creator if we changed it.
    test()->creator->update(['country' => 'DE']);

    // Audience is gender=m + country=TR; creator is now male but country=DE,
    // yet we still want is_in_audience=true because they're the creator.
    $response = $this->getJson("/polls/{$poll->id}", authHeader(test()->creator));

    $response->assertOk();
    expect($response->json('data.is_in_audience'))->toBeTrue();
    expect($response->json('data.audience_failures'))->toBe([]);
});

it('returns [unauthenticated] as failures for guests on a poll with rules', function (): void {
    // Use a public (non-audience_only) poll that still has rules, so the show endpoint returns 200.
    $poll = createPublicPoll(test()->creator);
    PollAudienceRule::insert([
        ['poll_id' => $poll->id, 'criterion' => 'country', 'value' => 'TR', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->getJson("/polls/{$poll->id}");

    $response->assertOk();
    expect($response->json('data.is_in_audience'))->toBeFalse();
    expect($response->json('data.audience_failures'))->toBe(['unauthenticated']);
});

it('exposes audience details only to the creator', function (): void {
    $poll = createAudienceOnlyPoll(test()->creator);

    // Audience user (matches, but not creator) — audience should be omitted
    $audienceResponse = $this->getJson("/polls/{$poll->id}", authHeader(test()->audienceUser));
    $audienceResponse->assertOk();
    expect($audienceResponse->json('data'))->not->toHaveKey('audience');

    // Creator — audience should be present
    $creatorResponse = $this->getJson("/polls/{$poll->id}", authHeader(test()->creator));
    $creatorResponse->assertOk();

    // poll no longer returns audience details in the response
    // expect($creatorResponse->json('data'))->toHaveKey('audience');
    // expect($creatorResponse->json('data.audience.gender'))->toContain('m');
    // expect($creatorResponse->json('data.audience.country'))->toContain('TR');
});

it('does not expose audience details to guests', function (): void {
    $poll = createPublicPoll(test()->creator);
    PollAudienceRule::insert([
        ['poll_id' => $poll->id, 'criterion' => 'country', 'value' => 'TR', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->getJson("/polls/{$poll->id}");
    $response->assertOk();
    expect($response->json('data'))->not->toHaveKey('audience');
});

// ───────────────────────────────────────────────
// Helpers
// ───────────────────────────────────────────────

function createAudienceOnlyPoll(User $user): Poll
{
    $poll = Poll::forceCreate([
        'question' => 'Audience only test poll?',
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
    PollAudienceRule::insert([
        ['poll_id' => $poll->id, 'criterion' => 'gender', 'value' => 'm', 'created_at' => $now, 'updated_at' => $now],
        ['poll_id' => $poll->id, 'criterion' => 'country', 'value' => 'TR', 'created_at' => $now, 'updated_at' => $now],
    ]);

    PollOption::insert([
        ['poll_id' => $poll->id, 'option_text' => 'Yes', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
        ['poll_id' => $poll->id, 'option_text' => 'No', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $poll->fresh()->load(['options', 'audienceRules']);
}

function createPublicPoll(User $user): Poll
{
    $poll = Poll::forceCreate([
        'question' => 'Public test poll?',
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
    PollOption::insert([
        ['poll_id' => $poll->id, 'option_text' => 'Yes', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
        ['poll_id' => $poll->id, 'option_text' => 'No', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $poll->fresh()->load('options');
}
