<?php

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;

beforeEach(function () {
    test()->user = User::factory()->create([
        'email' => 'poll_feat@example.com',
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

it('lists polls as guest', function () {
    createActivePollForFeature(test()->user);

    $response = $this->getJson('/polls');

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['polls']]);
});

it('lists polls as authenticated user', function () {
    createActivePollForFeature(test()->user);

    $response = $this->getJson('/polls', authHeader(test()->user));

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['polls', 'current_page', 'last_page', 'per_page', 'total']]);
});

// ───────────────────────────────────────────────
// Show (single poll)
// ───────────────────────────────────────────────

it('shows a single poll by ID', function () {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->getJson("/polls/{$poll->id}");

    $response->assertOk();
});

it('returns 404 for non-existent poll', function () {
    $response = $this->getJson('/polls/99999');

    $response->assertStatus(404);
});

// ───────────────────────────────────────────────
// Store (create poll)
// ───────────────────────────────────────────────

it('creates a poll as authenticated citizen', function () {
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

it('returns 401 when creating poll without authentication', function () {
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

it('returns 422 when creating poll with missing fields', function () {
    $response = $this->postJson('/polls', [
        'question' => 'Missing fields?',
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

it('returns 422 when poll has less than 2 options', function () {
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
// Vote
// ───────────────────────────────────────────────

it('allows voting on an active poll via API', function () {
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

it('returns 401 when voting without authentication', function () {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson('/polls/vote', [
        'poll_id' => $poll->id,
        'poll_option_id' => [$poll->options->first()->id],
    ]);

    $response->assertStatus(401);
});

it('returns 422 when voting with missing poll_id', function () {
    $response = $this->postJson('/polls/vote', [
        'poll_option_id' => [1],
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

// ───────────────────────────────────────────────
// React
// ───────────────────────────────────────────────

it('allows reacting to an active poll via API', function () {
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

it('returns 422 with invalid reaction value', function () {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson('/polls/react', [
        'poll_id' => $poll->id,
        'reaction' => 'invalid',
    ], authHeader(test()->user));

    $response->assertStatus(422);
});

it('returns 401 when reacting without authentication', function () {
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

it('toggles poll status as authenticated user', function () {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson("/polls/status/{$poll->id}", [], authHeader(test()->user));

    $response->assertOk();
    expect(Poll::withTrashed()->find($poll->id)->trashed())->toBeTrue();
});

it('returns 401 when toggling status without authentication', function () {
    $poll = createActivePollForFeature(test()->user);

    $response = $this->postJson("/polls/status/{$poll->id}");

    $response->assertStatus(401);
});

// ───────────────────────────────────────────────
// Helper
// ───────────────────────────────────────────────

function createActivePollForFeature(User $user): Poll
{
    $poll = Poll::create([
        'question' => 'Feature test poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 2,
        'audience_can_add_options' => false,
        'created_by' => $user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience' => [],
        'is_private' => false,
    ]);

    PollOption::insert([
        ['poll_id' => $poll->id, 'option_text' => 'Option A', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
        ['poll_id' => $poll->id, 'option_text' => 'Option B', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
        ['poll_id' => $poll->id, 'option_text' => 'Option C', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $poll->fresh()->load('options');
}
