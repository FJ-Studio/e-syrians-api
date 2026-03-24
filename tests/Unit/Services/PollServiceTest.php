<?php

use App\Exceptions\PollReactionException;
use App\Exceptions\PollVotingException;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use App\Services\PollService;

beforeEach(function () {
    test()->pollService = app(PollService::class);

    test()->user = User::factory()->create([
        'email' => 'poll_test@example.com',
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
// Create Poll
// ───────────────────────────────────────────────

it('creates a poll with options', function () {
    $poll = test()->pollService->createPoll([
        'question' => 'Test question?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Option A', 'Option B', 'Option C'],
    ], test()->user->id);

    expect($poll)->toBeInstanceOf(Poll::class);
    expect($poll->question)->toBe('Test question?');
    expect($poll->options()->count())->toBe(3);
    expect($poll->created_by)->toBe(test()->user->id);
});

it('sets audience correctly', function () {
    $poll = test()->pollService->createPoll([
        'question' => 'Targeted question?',
        'start_date' => now()->toDateString(),
        'duration' => 30,
        'max_selections' => 2,
        'audience_can_add_options' => true,
        'reveal_results' => 'after-voting',
        'voters_are_visible' => false,
        'options' => ['Yes', 'No'],
        'gender' => ['m'],
        'country' => ['TR', 'US'],
        'min_age' => 18,
        'max_age' => 65,
    ], test()->user->id);

    expect($poll->audience['gender'])->toBe(['m']);
    expect($poll->audience['country'])->toBe(['TR', 'US']);
    expect($poll->audience['age_range']['min'])->toBe(18);
    expect($poll->audience['age_range']['max'])->toBe(65);
});

// ───────────────────────────────────────────────
// Vote
// ───────────────────────────────────────────────

it('allows voting on an active poll', function () {
    $poll = createActivePoll(test()->user);

    test()->pollService->vote(
        $poll->id,
        [$poll->options->first()->id],
        test()->user->id,
    );

    $this->assertDatabaseHas('poll_votes', [
        'poll_id' => $poll->id,
        'user_id' => test()->user->id,
    ]);
});

it('prevents voting on a poll that has not started yet', function () {
    $poll = Poll::create([
        'question' => 'Future poll?',
        'start_date' => now()->addDays(5),
        'end_date' => now()->addDays(12),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience' => [],
        'is_private' => false,
    ]);
    $option = PollOption::create([
        'poll_id' => $poll->id,
        'option_text' => 'Option',
        'created_by' => test()->user->id,
    ]);

    test()->pollService->vote($poll->id, [$option->id], test()->user->id);
})->throws(PollVotingException::class, 'poll_has_not_started_yet');

it('prevents voting on an expired poll', function () {
    $poll = Poll::create([
        'question' => 'Expired poll?',
        'start_date' => now()->subDays(10),
        'end_date' => now()->subDays(1),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience' => [],
        'is_private' => false,
    ]);
    $option = PollOption::create([
        'poll_id' => $poll->id,
        'option_text' => 'Option',
        'created_by' => test()->user->id,
    ]);

    test()->pollService->vote($poll->id, [$option->id], test()->user->id);
})->throws(PollVotingException::class, 'poll_has_expired');

it('prevents double voting', function () {
    $poll = createActivePoll(test()->user);
    $optionId = $poll->options->first()->id;

    test()->pollService->vote($poll->id, [$optionId], test()->user->id);
    test()->pollService->vote($poll->id, [$optionId], test()->user->id);
})->throws(PollVotingException::class, 'you_have_already_voted');

it('prevents selecting more options than max_selections', function () {
    $poll = createActivePoll(test()->user, maxSelections: 1);
    $optionIds = $poll->options->pluck('id')->toArray();

    test()->pollService->vote($poll->id, $optionIds, test()->user->id);
})->throws(PollVotingException::class, 'user_has_reached_the_max_selections');

it('rejects invalid option IDs', function () {
    $poll = createActivePoll(test()->user);

    test()->pollService->vote($poll->id, [99999], test()->user->id);
})->throws(PollVotingException::class, 'invalid_options');

// ───────────────────────────────────────────────
// React
// ───────────────────────────────────────────────

it('allows reacting to an active poll', function () {
    $poll = createActivePoll(test()->user);

    test()->pollService->react($poll->id, 'up', test()->user->id);

    $this->assertDatabaseHas('poll_reactions', [
        'poll_id' => $poll->id,
        'user_id' => test()->user->id,
        'reaction' => 'up',
    ]);
});

it('replaces previous reaction', function () {
    $poll = createActivePoll(test()->user);

    test()->pollService->react($poll->id, 'up', test()->user->id);
    test()->pollService->react($poll->id, 'down', test()->user->id);

    expect($poll->reactions()->where('user_id', test()->user->id)->count())->toBe(1);
    $this->assertDatabaseHas('poll_reactions', [
        'poll_id' => $poll->id,
        'user_id' => test()->user->id,
        'reaction' => 'down',
    ]);
});

it('prevents reacting to an expired poll', function () {
    $poll = Poll::create([
        'question' => 'Expired react poll?',
        'start_date' => now()->subDays(10),
        'end_date' => now()->subDays(1),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience' => [],
        'is_private' => false,
    ]);

    test()->pollService->react($poll->id, 'up', test()->user->id);
})->throws(PollReactionException::class, 'poll_has_expired');

// ───────────────────────────────────────────────
// Toggle Status
// ───────────────────────────────────────────────

it('soft deletes an active poll', function () {
    $poll = createActivePoll(test()->user);

    test()->pollService->toggleStatus($poll->id);

    expect(Poll::withTrashed()->find($poll->id)->trashed())->toBeTrue();
});

it('restores a soft-deleted poll', function () {
    $poll = createActivePoll(test()->user);
    $poll->delete();

    test()->pollService->toggleStatus($poll->id);

    expect(Poll::find($poll->id))->not->toBeNull();
});

// ───────────────────────────────────────────────
// Reveal Results
// ───────────────────────────────────────────────

it('reveals results before voting', function () {
    $poll = createActivePoll(test()->user, revealResults: 'before-voting');

    expect(test()->pollService->shouldRevealResults($poll, null))->toBeTrue();
});

it('reveals results after expiration', function () {
    $poll = Poll::create([
        'question' => 'Expired reveal?',
        'start_date' => now()->subDays(10),
        'end_date' => now()->subDays(1),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->user->id,
        'reveal_results' => 'after-expiration',
        'voters_are_visible' => true,
        'audience' => [],
        'is_private' => false,
    ]);

    expect(test()->pollService->shouldRevealResults($poll, null))->toBeTrue();
});

it('hides results before expiration for after-expiration polls', function () {
    $poll = createActivePoll(test()->user, revealResults: 'after-expiration');

    expect(test()->pollService->shouldRevealResults($poll, null))->toBeFalse();
});

it('reveals results after user has voted', function () {
    $poll = createActivePoll(test()->user, revealResults: 'after-voting');

    test()->pollService->vote($poll->id, [$poll->options->first()->id], test()->user->id);

    expect(test()->pollService->shouldRevealResults($poll, test()->user))->toBeTrue();
});

it('hides results for after-voting polls when user has not voted', function () {
    $poll = createActivePoll(test()->user, revealResults: 'after-voting');

    expect(test()->pollService->shouldRevealResults($poll, test()->user))->toBeFalse();
});

// ───────────────────────────────────────────────
// Helper
// ───────────────────────────────────────────────

function createActivePoll(User $user, int $maxSelections = 2, string $revealResults = 'before-voting'): Poll
{
    $poll = Poll::create([
        'question' => 'Active test poll?',
        'start_date' => now()->subDays(1),
        'end_date' => now()->addDays(7),
        'max_selections' => $maxSelections,
        'audience_can_add_options' => false,
        'created_by' => $user->id,
        'reveal_results' => $revealResults,
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
