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

/**
 * Audience exposure rule (clarified 2026-06):
 *
 *   - **Demographic / criteria-based audience** (gender, age, country,
 *     ethnicity, hometown, religion …) is NOT considered sensitive —
 *     it describes which *groups* the poll targets, not which
 *     individuals. The mobile/web `AudienceCriteriaSheet` renders the
 *     rules next to per-row match / doesn't-match pills, so the
 *     `audience` key is exposed to ALL viewers (guest, audience
 *     member, non-member, creator).
 *
 *   - **Explicit-list audience** (`allowed_voters`: hand-picked UUIDs
 *     of invited users) IS sensitive — surfacing it would leak the
 *     author's guest list. The `audience` key is omitted from this
 *     public resource for EVERY viewer, including the creator. The
 *     creator only needs the list when editing the poll, which is
 *     served from a dedicated creator-only edit endpoint (TBD); the
 *     public `/polls/{id}` should not double as the edit data source.
 *     Everyone — including the creator on the public page — only
 *     learns "am I in?" via the `is_in_audience` / `audience_failures`
 *     pair.
 *
 * The first two tests below previously asserted that the `audience`
 * key was suppressed across the board on criteria-based polls — that
 * was the old rule, written before the AudienceCriteriaSheet existed.
 * They were renamed and inverted to match the current rule.
 *
 * The third test (added 2026-06) covers the explicit-list suppression
 * path — previously uncovered.
 */
it('exposes criteria-based audience details to every viewer', function (): void {
    $poll = createAudienceOnlyPoll(test()->creator);

    // Audience user (matches the criteria) — sees the audience rules.
    $audienceResponse = $this->getJson("/polls/{$poll->id}", authHeader(test()->audienceUser));
    $audienceResponse->assertOk();
    expect($audienceResponse->json('data'))->toHaveKey('audience');
    expect($audienceResponse->json('data.audience'))->not->toBeNull();

    // Creator — also sees the audience rules.
    $creatorResponse = $this->getJson("/polls/{$poll->id}", authHeader(test()->creator));
    $creatorResponse->assertOk();
    expect($creatorResponse->json('data'))->toHaveKey('audience');
    expect($creatorResponse->json('data.audience'))->not->toBeNull();
});

it('exposes criteria-based audience details to guests', function (): void {
    $poll = createPublicPoll(test()->creator);
    PollAudienceRule::insert([
        ['poll_id' => $poll->id, 'criterion' => 'country', 'value' => 'TR', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->getJson("/polls/{$poll->id}");
    $response->assertOk();
    expect($response->json('data'))->toHaveKey('audience');
    expect($response->json('data.audience'))->not->toBeNull();
});

it('suppresses explicit-list audience details for every viewer', function (): void {
    // Audience defined by an explicit invite list: only `audience@gmail.com`
    // is allowed. That email is the `audienceUser` fixture; the outsider
    // and the creator are NOT in the list.
    $poll = createPublicPoll(test()->creator);
    PollAudienceRule::insert([
        ['poll_id' => $poll->id, 'criterion' => 'allowed_voter', 'value' => 'audience@gmail.com', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Guest — `audience` key omitted; only the boolean signal is exposed.
    $guestResponse = $this->getJson("/polls/{$poll->id}");
    $guestResponse->assertOk();
    expect($guestResponse->json('data'))->not->toHaveKey('audience');
    expect($guestResponse->json('data'))->toHaveKey('is_in_audience');
    expect($guestResponse->json('data'))->toHaveKey('audience_failures');
    expect($guestResponse->json('data.audience_is_explicit_list'))->toBeTrue();

    // Sanctum's StatefulGuard caches the resolved user inside a single
    // test method, so a fresh bearer token in the next request is
    // ignored unless we forget the cached user first. Without this,
    // the audience check would run against the previously-resolved
    // user (e.g. the audienceUser when checking the outsider), and
    // `is_in_audience` would lie.
    auth('sanctum')->forgetUser();

    // Invited audience user — same suppression. They learn they're IN
    // via `is_in_audience` without seeing who else was invited.
    $audienceResponse = $this->getJson("/polls/{$poll->id}", authHeader(test()->audienceUser));
    $audienceResponse->assertOk();
    expect($audienceResponse->json('data'))->not->toHaveKey('audience');
    expect($audienceResponse->json('data.is_in_audience'))->toBeTrue();

    auth('sanctum')->forgetUser();

    // Non-invited outsider — same suppression.
    $outsiderResponse = $this->getJson("/polls/{$poll->id}", authHeader(test()->outsider));
    $outsiderResponse->assertOk();
    expect($outsiderResponse->json('data'))->not->toHaveKey('audience');
    expect($outsiderResponse->json('data.is_in_audience'))->toBeFalse();

    auth('sanctum')->forgetUser();

    // Creator — `audience` key STILL omitted from this public endpoint.
    // The creator reads their own `allowed_voters` list from the
    // dedicated creator-only edit endpoint (see next test); the
    // public poll page is not the right surface for that data.
    $creatorResponse = $this->getJson("/polls/{$poll->id}", authHeader(test()->creator));
    $creatorResponse->assertOk();
    expect($creatorResponse->json('data'))->not->toHaveKey('audience');
});

it('exposes the allowlist on the creator-only edit endpoint', function (): void {
    // Inverse of the suppression test above: the creator MUST be
    // able to fetch their explicit-list audience back, otherwise
    // the edit form can't tell the poll is allowlisted and would
    // silently wipe the audience on save.
    $poll = createPublicPoll(test()->creator);
    PollAudienceRule::insert([
        ['poll_id' => $poll->id, 'criterion' => 'allowed_voter', 'value' => 'guest1@example.com', 'created_at' => now(), 'updated_at' => now()],
        ['poll_id' => $poll->id, 'criterion' => 'allowed_voter', 'value' => 'guest2@example.com', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->getJson("/polls/{$poll->id}/edit", authHeader(test()->creator));
    $response->assertOk();
    expect($response->json('data.audience.allowed_voters'))
        ->toContain('guest1@example.com')
        ->toContain('guest2@example.com');
});

it('rejects the edit endpoint for non-creators', function (): void {
    $poll = createPublicPoll(test()->creator);

    auth('sanctum')->forgetUser();
    $response = $this->getJson("/polls/{$poll->id}/edit", authHeader(test()->outsider));
    $response->assertStatus(403);
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
