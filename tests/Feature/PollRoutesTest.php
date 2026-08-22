<?php

use App\Models\Poll;
use App\Models\User;
use App\Models\PollVote;
use App\Models\PollOption;
use App\Models\PollAudienceRule;

beforeEach(function (): void {
    test()->user = User::factory()->create([
        'email' => 'poll_feat@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'TR',
        'ethnicity' => 'arab',
    ]);
    test()->user->assignRole('citizen');
});

// ───────────────────────────────────────────────
// Index (list polls)
// ───────────────────────────────────────────────

it('lists polls as guest', function (): void {
    createActivePollForFeature(test()->user);

    $response = $this->getJson('/polls');

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['polls']]);
});

it('lists polls as authenticated user', function (): void {
    createActivePollForFeature(test()->user);

    $response = $this->getJson('/polls', authHeader(test()->user));

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['polls', 'current_page', 'last_page', 'per_page', 'total']]);
});

// ───────────────────────────────────────────────
// Show (single poll)
// ───────────────────────────────────────────────

it('shows a single poll by ID', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->getJson("/polls/{$poll->id}");

    $response->assertOk();
});

it('returns 404 for non-existent poll', function (): void {
    $response = $this->getJson('/polls/99999');

    $response->assertStatus(404);
});

// ───────────────────────────────────────────────
// Store (create poll)
// ───────────────────────────────────────────────

it('creates a poll as authenticated citizen', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Feature test poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
    ], authHeader(test()->user));

    $response->assertOk();
    $this->assertDatabaseHas('polls', ['question' => 'Feature test poll?']);
});

it('returns 401 when creating poll without authentication', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Unauthorized poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
    ]);

    $response->assertStatus(401);
});

it('returns 422 when creating poll with missing fields', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Missing fields?',
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

it('returns 422 when poll has less than 2 options', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Too few options?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Only one'],
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

// ───────────────────────────────────────────────
// Store — allowed_voters validation
// ───────────────────────────────────────────────

it('creates a poll with valid allowed_voters emails', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Allowed voters poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'allowed_voters' => ['user1@gmail.com', 'user2@test.org'],
    ], authHeader(test()->user));

    $response->assertOk();
    $this->assertDatabaseHas('polls', ['question' => 'Allowed voters poll?']);
});

it('creates a poll with valid allowed_voters national IDs', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'National ID voters poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'allowed_voters' => ['12345678', '98765432100'],
    ], authHeader(test()->user));

    $response->assertOk();
});

it('creates a poll with mixed emails and national IDs in allowed_voters', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Mixed voters poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'allowed_voters' => ['user@gmail.com', '12345678'],
    ], authHeader(test()->user));

    $response->assertOk();
});

it('rejects allowed_voters with invalid entries', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Invalid voters poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'allowed_voters' => ['not-an-email-or-id', 'abc'],
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

it('rejects allowed_voters with short national IDs', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Short ID poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'allowed_voters' => ['1234'], // less than 5 digits
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

it('rejects allowed_voters exceeding max 500 entries', function (): void {
    $voters = array_map(fn ($i) => "user{$i}@gmail.com", range(1, 501));

    $response = $this->postJson('/polls', [
        'question' => 'Too many voters poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'allowed_voters' => $voters,
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

it('accepts allowed_voters with exactly 500 entries', function (): void {
    $voters = array_map(fn ($i) => "user{$i}@gmail.com", range(1, 500));

    $response = $this->postJson('/polls', [
        'question' => 'Max voters poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'allowed_voters' => $voters,
    ], authHeader(test()->user));

    $response->assertOk();
});

// ───────────────────────────────────────────────
// Store — province validation
// ───────────────────────────────────────────────

it('creates a poll with province audience', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'City inside Syria poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'country' => ['SY'],
        'province' => ['damascus', 'daraa'],
    ], authHeader(test()->user));

    $response->assertOk();
    $this->assertDatabaseHas('polls', ['question' => 'City inside Syria poll?']);
});

it('rejects invalid province values', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Invalid city poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'province' => ['not_a_real_city_xyz'],
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

// ───────────────────────────────────────────────
// Vote
// ───────────────────────────────────────────────

it('allows voting on an active poll via API', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson('/polls/vote', [
        'poll_id' => $poll->id,
        'poll_option_id' => [$poll->options->first()->id],
    ], authHeader(test()->user));

    $response->assertOk();
    $this->assertDatabaseHas('poll_votes', [
        'poll_id' => $poll->id,
        'user_id' => test()->user->id,
    ]);
});

it('returns 401 when voting without authentication', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson('/polls/vote', [
        'poll_id' => $poll->id,
        'poll_option_id' => [$poll->options->first()->id],
    ]);

    $response->assertStatus(401);
});

it('returns 422 when voting with missing poll_id', function (): void {
    $response = $this->postJson('/polls/vote', [
        'poll_option_id' => [1],
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

// ───────────────────────────────────────────────
// React
// ───────────────────────────────────────────────

it('allows reacting to an active poll via API', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson('/polls/react', [
        'poll_id' => $poll->id,
        'reaction' => 'up',
    ], authHeader(test()->user));

    $response->assertOk();
    $this->assertDatabaseHas('poll_reactions', [
        'poll_id' => $poll->id,
        'user_id' => test()->user->id,
        'reaction' => 'up',
    ]);
});

it('returns 422 with invalid reaction value', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson('/polls/react', [
        'poll_id' => $poll->id,
        'reaction' => 'invalid',
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

it('returns 401 when reacting without authentication', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson('/polls/react', [
        'poll_id' => $poll->id,
        'reaction' => 'up',
    ]);

    $response->assertStatus(401);
});

// ───────────────────────────────────────────────
// Toggle Status
// ───────────────────────────────────────────────

it('toggles poll status as authenticated user', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson("/polls/status/{$poll->id}", [], authHeader(test()->user));

    $response->assertOk();
    expect(Poll::withTrashed()->find($poll->id)->trashed())->toBeTrue();
});

it('returns 401 when toggling status without authentication', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson("/polls/status/{$poll->id}");

    $response->assertStatus(401);
});

/*
 * Ownership gate on the status toggle. Before the My Polls work,
 * PollController::status would happily close any poll for any
 * authed user — letting Person A soft-delete Person B's poll.
 * The controller now checks `created_by` and 403s otherwise.
 */
it('rejects toggling status of a poll owned by another user', function (): void {
    $owner = User::factory()->create(['verified_at' => now()]);
    $owner->assignRole('citizen');
    $poll = createActivePollForFeature($owner);

    $response = $this->postJson(
        "/polls/status/{$poll->id}",
        [],
        authHeader(test()->user),
    );

    $response->assertStatus(403);
    // Sanity: the poll must NOT have been soft-deleted by the
    // rejected request.
    expect(Poll::find($poll->id))->not->toBeNull()
        ->and(Poll::find($poll->id)->trashed())->toBeFalse();
});

/*
 * Round-trip the status toggle and assert the response carries
 * the updated `deleted_at` so the client can patch its row state
 * without a list refetch. Re-toggling reopens the poll and the
 * value flips back to null.
 */
it('returns updated deleted_at on close and reopen', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $close = $this->postJson(
        "/polls/status/{$poll->id}",
        [],
        authHeader(test()->user),
    );

    $close->assertOk();
    expect($close->json('data.id'))->toEqual($poll->id)
        ->and($close->json('data.deleted_at'))->not->toBeNull();

    $reopen = $this->postJson(
        "/polls/status/{$poll->id}",
        [],
        authHeader(test()->user),
    );

    $reopen->assertOk();
    expect($reopen->json('data.deleted_at'))->toBeNull();
});

// ───────────────────────────────────────────────
// Edit poll (PATCH /polls/{poll}) — vote-locked
// ───────────────────────────────────────────────

it('lets the creator edit a poll that has no votes', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        [
            'question' => 'Edited question?',
            'max_selections' => 1,
            'recaptcha_token' => 'test',
        ],
        authHeader(test()->user),
    );

    $response->assertOk();
    expect($response->json('data.question'))->toEqual('Edited question?')
        ->and($response->json('data.max_selections'))->toEqual(1);
    expect(Poll::find($poll->id)->question)->toEqual('Edited question?');
});

it('rejects editing a poll owned by another user', function (): void {
    $owner = User::factory()->create(['verified_at' => now()]);
    $owner->assignRole('citizen');
    $poll = createActivePollForFeature($owner);

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        ['question' => 'Hijack attempt?', 'recaptcha_token' => 'test'],
        authHeader(test()->user),
    );

    $response->assertStatus(403);
    expect(Poll::find($poll->id)->question)->toEqual('Feature test poll?');
});

it('rejects editing a poll once a vote has been cast', function (): void {
    $poll = createActivePollForFeature(test()->user);
    /** @var PollOption $option */
    $option = $poll->options()->first();

    // Cast a vote directly so we exercise the gate, not the
    // /polls/vote endpoint's own validation.
    PollVote::create([
        'poll_id' => $poll->id,
        'poll_option_id' => $option->id,
        'user_id' => test()->user->id,
    ]);

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        ['question' => 'Too late to edit?', 'recaptcha_token' => 'test'],
        authHeader(test()->user),
    );

    $response->assertStatus(403);
    expect(Poll::find($poll->id)->question)->toEqual('Feature test poll?');
});

// Regression: partial PATCH — sending only `question` shouldn't
// 422 because UpdatePollRequest::prepareForValidation used to
// merge empty `duration` / `max_selections` defaults, defeating
// `sometimes` validation.
it('accepts a partial PATCH that only touches the question', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        ['question' => 'Just the question', 'recaptcha_token' => 'test'],
        authHeader(test()->user),
    );

    $response->assertOk();
    expect(Poll::find($poll->id)->question)->toEqual('Just the question');
    // Unrelated fields stay intact.
    expect(Poll::find($poll->id)->max_selections)->toEqual(2);
});

// Regression: poll already started — `start_date` is intentionally
// omitted from the PATCH so the `after_or_equal:today` rule doesn't
// fire. The backend reuses the existing start_date when computing
// end_date from the new duration.
it('allows editing duration on a poll that started yesterday', function (): void {
    $poll = createActivePollForFeature(test()->user);
    // Sanity: createActivePollForFeature uses start_date = yesterday.
    expect($poll->start_date->isPast())->toBeTrue();

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        ['duration' => 14, 'recaptcha_token' => 'test'],
        authHeader(test()->user),
    );

    $response->assertOk();
    $fresh = Poll::find($poll->id);
    expect($fresh->end_date->toDateString())
        ->toEqual($poll->start_date->copy()->addDays(14)->toDateString());
});

// Regression: private polls were 404'ing on PATCH because the
// `public_polls` global scope hid them from route binding. The
// AppServiceProvider's custom Route::bind('poll', ...) now drops
// the scope and re-applies the privacy rule explicitly: creators
// see their own private polls, everyone else still gets 404.
it('lets the creator edit their private poll', function (): void {
    $poll = createActivePollForFeature(test()->user);
    $poll->forceFill(['is_private' => true])->save();

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        ['question' => 'Edited private', 'recaptcha_token' => 'test'],
        authHeader(test()->user),
    );

    $response->assertOk();
    expect(Poll::withoutGlobalScope('public_polls')->find($poll->id)->question)
        ->toEqual('Edited private');
});

it('still 404s a private poll for a non-creator viewer', function (): void {
    $owner = User::factory()->create(['verified_at' => now()]);
    $owner->assignRole('citizen');
    $poll = createActivePollForFeature($owner);
    $poll->forceFill(['is_private' => true])->save();

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        ['question' => 'Snoop attempt', 'recaptcha_token' => 'test'],
        authHeader(test()->user),
    );

    $response->assertStatus(404);
});

// Regression: allowlist preservation — editing a poll that has an
// explicit-voter-list audience without sending audience keys must
// leave the allowlist intact. (The web edit form used to wipe it
// because PollResource hid `allowed_voters`.)
it('leaves the allowlist intact on a scalar-only PATCH', function (): void {
    $poll = createActivePollForFeature(test()->user);
    PollAudienceRule::insert([
        ['poll_id' => $poll->id, 'criterion' => 'allowed_voter', 'value' => 'someone@example.com', 'created_at' => now(), 'updated_at' => now()],
        ['poll_id' => $poll->id, 'criterion' => 'allowed_voter', 'value' => 'other@example.com', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        ['question' => 'Edited but allowlist stays', 'recaptcha_token' => 'test'],
        authHeader(test()->user),
    );

    $response->assertOk();
    $allowlist = PollAudienceRule::where('poll_id', $poll->id)
        ->where('criterion', 'allowed_voter')
        ->pluck('value')
        ->all();
    expect($allowlist)->toContain('someone@example.com')
        ->and($allowlist)->toContain('other@example.com');
});

// ───────────────────────────────────────────────
// Helper
// ───────────────────────────────────────────────

function createActivePollForFeature(User $user): Poll
{
    $poll = Poll::forceCreate([
        'question' => 'Feature test poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 2,
        'audience_can_add_options' => false,
        'created_by' => $user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
    ]);

    PollOption::insert([
        ['poll_id' => $poll->id, 'option_text' => 'Option A', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
        ['poll_id' => $poll->id, 'option_text' => 'Option B', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
        ['poll_id' => $poll->id, 'option_text' => 'Option C', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $poll->fresh()->load('options');
}
