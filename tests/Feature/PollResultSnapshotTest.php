<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use App\Models\PollVote;
use App\Models\PollOption;
use App\Services\PollService;
use App\Models\PollAudienceRule;
use App\Models\PollResultSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use App\Exceptions\PollVotingException;
use Illuminate\Database\QueryException;
use App\Exceptions\PollReactionException;
use Illuminate\Support\Facades\Exceptions;
use App\Services\PollResultSnapshotService;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake();
});

function resultSnapshotPoll(array $attributes = []): Poll
{
    $owner = User::factory()->create(['verified_at' => now()]);
    $owner->assignRole('citizen');
    $poll = Poll::forceCreate([
        'question' => 'Which proposal do you support?',
        'start_date' => now()->subDays(7),
        'end_date' => now()->subSecond(),
        'created_by' => $owner->id,
        'max_selections' => 2,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => false,
        'is_private' => false,
        'audience_only' => false,
        ...$attributes,
    ]);
    foreach (['First proposal', 'Second proposal', 'Neither'] as $text) {
        PollOption::forceCreate(['poll_id' => $poll->id, 'option_text' => $text, 'created_by' => $owner->id]);
    }

    return $poll->load('options', 'user');
}

function castSnapshotFixtureVotes(Poll $poll): void
{
    $second = User::factory()->create();
    foreach ([[$poll->created_by, 0], [$poll->created_by, 1], [$second->id, 0]] as [$userId, $option]) {
        PollVote::create(['poll_id' => $poll->id, 'poll_option_id' => $poll->options[$option]->id, 'user_id' => $userId]);
    }
}

it('captures expired poll results without storing participant identities', function (): void {
    $poll = resultSnapshotPoll();
    castSnapshotFixtureVotes($poll);

    $snapshot = resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);

    expect($snapshot)->toBeInstanceOf(PollResultSnapshot::class)
        ->and($snapshot->question)->toBe($poll->question)
        ->and($snapshot->total_votes)->toBe(3)
        ->and($snapshot->unique_voters_count)->toBe(2)
        ->and($snapshot->schema_version)->toBe(1)
        ->and($snapshot->closed_at->equalTo($poll->end_date))->toBeTrue()
        ->and($snapshot->captured_at->equalTo(now()))->toBeTrue()
        ->and(array_column($snapshot->options, 'votes_count'))->toBe([2, 1, 0])
        ->and(array_column($snapshot->options, 'percentage'))->toEqual([66.67, 33.33, 0]);
    foreach ($snapshot->options as $option) {
        expect(array_keys($option))->toBe(['id', 'poll_id', 'option_text', 'created_at', 'votes_count', 'percentage']);
    }
    expect(Schema::getColumnListing('poll_result_snapshots'))->not->toContain('user_id', 'created_by', 'ip_address', 'user_agent');
});

it('captures zero-vote results with zero percentages', function (): void {
    $poll = resultSnapshotPoll();

    $snapshot = resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);

    expect($snapshot->total_votes)->toBe(0)
        ->and($snapshot->unique_voters_count)->toBe(0)
        ->and(array_column($snapshot->options, 'percentage'))->toEqual([0, 0, 0]);
});

it('does not finalize active or future polls but includes the exact expiry boundary', function (int $secondsUntilEnd, bool $eligible): void {
    $poll = resultSnapshotPoll(['end_date' => now()->addSeconds($secondsUntilEnd)]);

    $snapshot = resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);

    expect($snapshot !== null)->toBe($eligible)
        ->and(PollResultSnapshot::count())->toBe($eligible ? 1 : 0);
})->with(['active' => [1, false], 'at expiry' => [0, true], 'expired' => [-1, true]]);

it('never recounts or replaces a snapshot after source votes or labels change', function (): void {
    $poll = resultSnapshotPoll();
    castSnapshotFixtureVotes($poll);
    $service = resolve(PollResultSnapshotService::class);
    $snapshot = $service->finalizeExpiredPoll($poll->id);
    $before = $snapshot->fresh()->getAttributes();
    $poll->votes()->delete();
    $poll->options()->update(['option_text' => 'Changed source label']);
    DB::table('polls')->where('id', $poll->id)->update(['question' => 'Changed source question']);
    $this->travel(1)->hour();

    $again = $service->finalizeExpiredPoll($poll->id);

    expect($again->getAttributes())->toBe($before)
        ->and(PollResultSnapshot::where('poll_id', $poll->id)->count())->toBe(1);
});

it('rejects model-level modification of an existing snapshot', function (): void {
    $poll = resultSnapshotPoll();
    $snapshot = resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);

    expect(fn () => $snapshot->update(['total_votes' => 999]))->toThrow(LogicException::class);
    expect($snapshot->fresh()->total_votes)->toBe(0);
    expect(fn () => $snapshot->fresh()->delete())->toThrow(LogicException::class);
});

it('enforces one snapshot per poll in the database', function (): void {
    $poll = resultSnapshotPoll();
    $snapshot = resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);

    expect(fn () => $snapshot->replicate()->save())->toThrow(QueryException::class);
});

it('finalizes private and soft-deleted expired polls without making them public', function (bool $private, bool $hidden): void {
    $poll = resultSnapshotPoll(['is_private' => $private, 'deleted_at' => $hidden ? now() : null]);

    resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);

    expect(PollResultSnapshot::where('poll_id', $poll->id)->exists())->toBeTrue();
    $stored = Poll::withoutGlobalScopes()->findOrFail($poll->id);
    expect((bool) $stored->is_private)->toBe($private)->and($stored->trashed())->toBe($hidden);
})->with(['private' => [true, false], 'hidden' => [false, true], 'private and hidden' => [true, true]]);

it('serves the frozen result through detail, public listing and owner listing after ballots disappear', function (): void {
    $poll = resultSnapshotPoll();
    castSnapshotFixtureVotes($poll);
    resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);
    $poll->votes()->delete();
    $poll->options()->delete();
    DB::table('polls')->where('id', $poll->id)->update(['question' => 'Changed live question']);

    $this->getJson('/polls/'.$poll->id)
        ->assertOk()->assertJsonPath('data.question', $poll->question)
        ->assertJsonPath('data.unique_voters_count', 2)
        ->assertJsonPath('data.votes_count', 2)
        ->assertJsonPath('data.options.0.option_text', 'First proposal')
        ->assertJsonPath('data.options.0.votes_count', 2)
        ->assertJsonPath('data.options.0.percentage', 66.67)
        ->assertJsonCount(3, 'data.options');
    $this->getJson('/polls?year='.$poll->created_at->year.'&month='.$poll->created_at->month)
        ->assertOk()->assertJsonFragment(['unique_voters_count' => 2, 'question' => $poll->question]);
    $this->getJson('/users/my-polls', authHeader($poll->user))
        ->assertOk()->assertJsonPath('data.polls.0.unique_voters_count', 2)
        ->assertJsonPath('data.polls.0.votes_count', 3)
        ->assertJsonPath('data.polls.0.is_editable', false);
});

it('keeps active poll results live and does not create snapshots in read requests', function (): void {
    $poll = resultSnapshotPoll(['end_date' => now()->addDay()]);
    castSnapshotFixtureVotes($poll);
    $this->getJson('/polls/'.$poll->id)->assertOk()->assertJsonPath('data.unique_voters_count', 2);
    $poll->votes()->delete();
    $this->getJson('/polls/'.$poll->id)->assertOk()->assertJsonPath('data.unique_voters_count', 0);

    expect(PollResultSnapshot::count())->toBe(0);
});

it('does not leak frozen percentages through after-voting result visibility', function (): void {
    $poll = resultSnapshotPoll(['reveal_results' => 'after-voting']);
    castSnapshotFixtureVotes($poll);
    resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);

    $this->getJson('/polls/'.$poll->id)->assertOk()->assertJsonMissingPath('data.options.0.percentage');
    $this->getJson('/polls/'.$poll->id, authHeader($poll->user))
        ->assertOk()->assertJsonPath('data.options.0.percentage', 66.67);
});

it('does not expose a private frozen poll to another account', function (): void {
    $poll = resultSnapshotPoll(['is_private' => true]);
    resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);
    $other = User::factory()->create();

    $this->getJson('/polls/'.$poll->id, authHeader($other))->assertNotFound();
});

it('does not bypass audience-only visibility when frozen results exist', function (): void {
    $poll = resultSnapshotPoll(['audience_only' => true]);
    PollAudienceRule::create(['poll_id' => $poll->id, 'criterion' => 'country', 'value' => 'US']);
    resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);
    $outside = User::factory()->create(['country' => 'DE']);

    $this->getJson('/polls/'.$poll->id, authHeader($outside))
        ->assertForbidden()->assertJsonPath('messages.0', 'poll_visible_to_targeted_audience_only');
});

it('rejects voting at the exact expiry time', function (): void {
    $poll = resultSnapshotPoll(['end_date' => now()]);

    expect(fn () => resolve(PollService::class)->vote($poll->id, [$poll->options[0]->id], $poll->created_by))
        ->toThrow(PollVotingException::class, 'poll_has_expired');
    expect($poll->votes()->count())->toBe(0);
});

it('prevents rescheduling or voting on a finalized poll even if its live end date is moved', function (): void {
    $poll = resultSnapshotPoll();
    resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);
    $poll->forceFill(['end_date' => now()->addDays(7)])->save();
    $service = resolve(PollService::class);

    expect(fn () => $service->updatePoll($poll, ['question' => 'Rewritten']))
        ->toThrow(PollVotingException::class, 'poll_has_expired');
    expect(fn () => $service->vote($poll->id, [$poll->options[0]->id], $poll->created_by))
        ->toThrow(PollVotingException::class, 'poll_has_expired');
    expect(fn () => $service->react($poll->id, 'up', $poll->created_by))
        ->toThrow(PollReactionException::class, 'poll_has_expired');
});

it('previews expired polls without capturing or modifying them', function (): void {
    resultSnapshotPoll();
    resultSnapshotPoll(['end_date' => now()->addDay()]);

    $this->artisan('polls:finalize-results --dry-run')
        ->expectsOutput('Expired polls awaiting snapshots: 1')
        ->expectsOutput('Preview only. No snapshots were created.')
        ->assertSuccessful();
    expect(PollResultSnapshot::count())->toBe(0);
});

it('finalizes expired polls independently and is idempotent across batches', function (): void {
    $poll = resultSnapshotPoll();
    $private = resultSnapshotPoll(['is_private' => true]);
    $hidden = resultSnapshotPoll(['deleted_at' => now()]);
    $active = resultSnapshotPoll(['end_date' => now()->addDay()]);

    $this->artisan('polls:finalize-results')
        ->expectsOutput('Snapshots created: 3; failures: 0')->assertSuccessful();
    $this->artisan('polls:finalize-results')
        ->expectsOutput('Snapshots created: 0; failures: 0')->assertSuccessful();
    expect(PollResultSnapshot::whereIn('poll_id', [$poll->id, $private->id, $hidden->id])->count())->toBe(3)
        ->and(PollResultSnapshot::where('poll_id', $active->id)->exists())->toBeFalse();
});

it('rolls back a failed capture, continues other polls and retries the failed poll later', function (): void {
    Exceptions::fake();
    $failed = resultSnapshotPoll();
    $other = resultSnapshotPoll();
    $shouldFail = true;
    PollResultSnapshot::creating(function (PollResultSnapshot $snapshot) use ($failed, &$shouldFail): void {
        if ($snapshot->poll_id === $failed->id && $shouldFail) {
            throw new RuntimeException('Snapshot fixture failure');
        }
    });

    $this->artisan('polls:finalize-results')
        ->expectsOutput('Snapshots created: 1; failures: 1')->assertFailed();
    expect(PollResultSnapshot::where('poll_id', $failed->id)->exists())->toBeFalse()
        ->and(PollResultSnapshot::where('poll_id', $other->id)->exists())->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);

    $shouldFail = false;
    $this->artisan('polls:finalize-results')
        ->expectsOutput('Snapshots created: 1; failures: 0')->assertSuccessful();
});

it('does not skip expired polls across chunk boundaries', function (): void {
    $template = resultSnapshotPoll();
    for ($i = 0; $i < 100; $i++) {
        $template->replicate()->save();
    }

    $this->artisan('polls:finalize-results')
        ->expectsOutput('Snapshots created: 101; failures: 0')->assertSuccessful();
    expect(PollResultSnapshot::count())->toBe(101);
});

it('rechecks a postponed expiry after a lifecycle batch has been loaded', function (): void {
    $first = resultSnapshotPoll();
    $postponed = resultSnapshotPoll();
    PollResultSnapshot::created(function (PollResultSnapshot $snapshot) use ($first, $postponed): void {
        if ($snapshot->poll_id === $first->id) {
            $postponed->forceFill(['end_date' => now()->addDay()])->save();
        }
    });

    $this->artisan('polls:finalize-results')
        ->expectsOutput('Snapshots created: 1; failures: 0')->assertSuccessful();
    expect(PollResultSnapshot::where('poll_id', $first->id)->exists())->toBeTrue()
        ->and(PollResultSnapshot::where('poll_id', $postponed->id)->exists())->toBeFalse();
});

it('schedules lifecycle finalization independently every minute without overlap', function (): void {
    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'polls:finalize-results'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('removes the snapshot only when its parent poll is permanently removed', function (): void {
    $poll = resultSnapshotPoll();
    resolve(PollResultSnapshotService::class)->finalizeExpiredPoll($poll->id);
    $poll->delete();
    expect(PollResultSnapshot::where('poll_id', $poll->id)->exists())->toBeTrue();

    $poll->forceDelete();
    expect(PollResultSnapshot::where('poll_id', $poll->id)->exists())->toBeFalse();
});
