<?php

use App\Models\Poll;
use App\Models\User;
use App\Models\PollOption;
use App\Services\PollService;
use App\Jobs\LogPollVoteToBigQuery;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SyncPollAudienceRulesToBigQuery;

beforeEach(function (): void {
    Queue::fake();
    test()->pollService = resolve(PollService::class);

    test()->user = User::factory()->create([
        'email' => 'bq_poll_test@gmail.com',
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

function createActivePollForBqTest(): Poll
{
    $poll = Poll::forceCreate([
        'question' => 'BQ Test Poll?',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'created_by' => test()->user->id,
    ]);

    PollOption::insert([
        ['poll_id' => $poll->id, 'option_text' => 'Option A', 'created_by' => test()->user->id, 'created_at' => now(), 'updated_at' => now()],
        ['poll_id' => $poll->id, 'option_text' => 'Option B', 'created_by' => test()->user->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $poll->fresh();
}

// ───────────────────────────────────────────────
// Vote BigQuery Dispatch
// ───────────────────────────────────────────────

it('dispatches BigQuery job when user votes', function (): void {
    $poll = createActivePollForBqTest();
    $optionId = $poll->options->first()->id;

    test()->pollService->vote($poll->id, [$optionId], test()->user->id);

    Queue::assertPushed(LogPollVoteToBigQuery::class, function ($job) {
        $reflection = new ReflectionClass($job);
        $userIdProp = $reflection->getProperty('userId');
        $userIdProp->setAccessible(true);

        return $userIdProp->getValue($job) === test()->user->id;
    });
});

// ───────────────────────────────────────────────
// Poll Creation Audience Sync
// ───────────────────────────────────────────────

it('dispatches audience rules sync on poll creation', function (): void {
    test()->pollService->createPoll([
        'question' => 'Audience poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'audience_only' => true,
        'options' => ['Yes', 'No'],
        'gender' => ['m'],
        'country' => ['TR', 'SY'],
    ], test()->user->id);

    Queue::assertPushed(SyncPollAudienceRulesToBigQuery::class);
});
