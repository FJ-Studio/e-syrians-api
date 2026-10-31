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
// Store — legacy allowed_voters rejection
// ───────────────────────────────────────────────

it('rejects legacy allowed_voters when creating a poll', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Legacy voters poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'allowed_voters' => ['user1@gmail.com', 'user2@test.org'],
    ], authHeader(test()->user));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('allowed_voters');
    $this->assertDatabaseMissing('polls', ['question' => 'Legacy voters poll?']);
});

it('rejects legacy allowed_voters even when the array is empty', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Empty legacy voters poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'allowed_voters' => [],
    ], authHeader(test()->user));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('allowed_voters');
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

it('rejects legacy allowed_voters when editing a poll', function (): void {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        [
            'allowed_voters' => ['someone@example.com'],
            'recaptcha_token' => 'test',
        ],
        authHeader(test()->user),
    );

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('allowed_voters');
    expect(PollAudienceRule::where('poll_id', $poll->id)->where('criterion', 'allowed_voter')->exists())->toBeFalse();
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

// Legacy safety: scalar-only edits must not delete historical
// `allowed_voter` rows. New API requests cannot create this rule
// type anymore, but existing restricted polls should not be opened
// accidentally by an unrelated edit.
it('leaves legacy allowed_voter rules intact on a scalar-only PATCH', function (): void {
    $poll = createActivePollForFeature(test()->user);
    PollAudienceRule::insert([
        ['poll_id' => $poll->id, 'criterion' => 'allowed_voter', 'value' => 'someone@example.com', 'created_at' => now(), 'updated_at' => now()],
        ['poll_id' => $poll->id, 'criterion' => 'allowed_voter', 'value' => 'other@example.com', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->patchJson(
        "/polls/{$poll->id}",
        ['question' => 'Edited but legacy restrictions stay', 'recaptcha_token' => 'test'],
        authHeader(test()->user),
    );

    $response->assertOk();
    $legacyRules = PollAudienceRule::where('poll_id', $poll->id)
        ->where('criterion', 'allowed_voter')
        ->pluck('value')
        ->all();
    expect($legacyRules)->toContain('someone@example.com')
        ->and($legacyRules)->toContain('other@example.com');
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
