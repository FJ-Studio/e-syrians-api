<?php

use App\Models\Poll;
use App\Models\User;
use App\Models\PollOption;

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
// Store — city_inside_syria validation
// ───────────────────────────────────────────────

it('creates a poll with city_inside_syria audience', function (): void {
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
        'city_inside_syria' => ['damascus', 'daraa'],
    ], authHeader(test()->user));

    $response->assertOk();
    $this->assertDatabaseHas('polls', ['question' => 'City inside Syria poll?']);
});

it('rejects invalid city_inside_syria values', function (): void {
    $response = $this->postJson('/polls', [
        'question' => 'Invalid city poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'city_inside_syria' => ['not_a_real_city_xyz'],
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
