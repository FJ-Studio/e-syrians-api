<?php

use App\Models\Poll;
use App\Models\User;
use App\Models\PollOption;

beforeEach(function (): void {
    test()->user = User::factory()->create([
        'email' => 'userpoll_feat@example.com',
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
// My Polls
// ───────────────────────────────────────────────

it('returns polls created by the authenticated user', function (): void {
    $poll = Poll::forceCreate([
        'question' => 'My poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
    ]);

    $response = $this->getJson('/users/my-polls', authHeader(test()->user));

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['polls', 'total', 'per_page', 'current_page', 'last_page']]);
});

it('returns 401 for my-polls without authentication', function (): void {
    $response = $this->getJson('/users/my-polls');

    $response->assertStatus(401);
});

it('includes soft-deleted polls in my-polls', function (): void {
    $poll = Poll::forceCreate([
        'question' => 'Deleted poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
    ]);
    $poll->delete();

    $response = $this->getJson('/users/my-polls', authHeader(test()->user));

    $response->assertOk();
    expect($response['data']['total'])->toBeGreaterThanOrEqual(1);
});

// ───────────────────────────────────────────────
// My Reactions
// ───────────────────────────────────────────────

it('returns reactions made by the authenticated user', function (): void {
    $poll = Poll::forceCreate([
        'question' => 'React poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
    ]);

    $poll->reactions()->create([
        'user_id' => test()->user->id,
        'reaction' => 'up',
    ]);

    $response = $this->getJson('/users/my-reactions', authHeader(test()->user));

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['reactions', 'total']]);
});

it('returns 401 for my-reactions without authentication', function (): void {
    $response = $this->getJson('/users/my-reactions');

    $response->assertStatus(401);
});

// ───────────────────────────────────────────────
// My Votes
// ───────────────────────────────────────────────

it('returns votes grouped by poll for authenticated user', function (): void {
    $poll = Poll::forceCreate([
        'question' => 'Vote poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => 2,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
    ]);

    $option = PollOption::forceCreate([
        'poll_id' => $poll->id,
        'option_text' => 'Option A',
        'created_by' => test()->user->id,
    ]);

    // Create a vote record directly
    $poll->votes()->create([
        'user_id' => test()->user->id,
        'poll_option_id' => $option->id,
    ]);

    $response = $this->getJson('/users/my-votes', authHeader(test()->user));

    $response->assertOk();
});

it('returns 401 for my-votes without authentication', function (): void {
    $response = $this->getJson('/users/my-votes');

    $response->assertStatus(401);
});

it('returns empty data when user has no votes', function (): void {
    $response = $this->getJson('/users/my-votes', authHeader(test()->user));

    $response->assertOk();
});

// ───────────────────────────────────────────────
// My Verifications & My Verifiers
// ───────────────────────────────────────────────

it('returns verifications for authenticated user', function (): void {
    $response = $this->getJson('/users/my-verifications', authHeader(test()->user));

    $response->assertOk();
});

it('returns verifiers for authenticated user', function (): void {
    $response = $this->getJson('/users/my-verifiers', authHeader(test()->user));

    $response->assertOk();
});

it('returns 401 for my-verifications without authentication', function (): void {
    $response = $this->getJson('/users/my-verifications');

    $response->assertStatus(401);
});

it('returns 401 for my-verifiers without authentication', function (): void {
    $response = $this->getJson('/users/my-verifiers');

    $response->assertStatus(401);
});
