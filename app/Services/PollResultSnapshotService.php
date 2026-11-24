<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollResultSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

class PollResultSnapshotService
{
    /**
     * Poll lifecycle operation only: never invoked by account deletion or GETs.
     * Voting/editing take the same poll lock, so all aggregate reads describe
     * one committed result. A retry returns the original snapshot unchanged.
     */
    public function finalizeExpiredPoll(int $pollId): ?PollResultSnapshot
    {
        return DB::transaction(function () use ($pollId): ?PollResultSnapshot {
            $poll = Poll::withTrashed()->withoutGlobalScope('public_polls')
                ->lockForUpdate()->findOrFail($pollId);
            $existing = $poll->resultSnapshot()->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($poll->end_date->isFuture()) {
                return null;
            }

            $totalVotes = $poll->votes()->count();
            $uniqueVoters = $poll->votes()->distinct()->count('user_id');
            $options = $poll->options()->orderBy('id')->withCount('votes')->get()
                ->map(static function (PollOption $option) use ($totalVotes): array {
                    $votes = (int) $option->votes_count;

                    return [
                        'id' => (int) $option->id,
                        'poll_id' => (int) $option->poll_id,
                        'option_text' => (string) $option->option_text,
                        'created_at' => $option->created_at?->toISOString(),
                        'votes_count' => $votes,
                        // Preserve the current API's selection-based formula.
                        'percentage' => $totalVotes > 0 ? round(($votes / $totalVotes) * 100, 2) : 0,
                    ];
                })->all();

            return $poll->resultSnapshot()->create([
                'schema_version' => 1,
                'question' => $poll->question,
                'start_date' => $poll->start_date,
                'end_date' => $poll->end_date,
                'max_selections' => $poll->max_selections,
                'total_votes' => $totalVotes,
                'unique_voters_count' => $uniqueVoters,
                'options' => $options,
                'closed_at' => $poll->end_date,
                'captured_at' => now(),
            ]);
        });
    }

    /** @return Builder<Poll> */
    public function awaitingSnapshots(): Builder
    {
        return Poll::withTrashed()->withoutGlobalScope('public_polls')
            ->where('end_date', '<=', now())->whereDoesntHave('resultSnapshot');
    }

    /** @return array{captured: int, failed: int} */
    public function finalizeExpired(): array
    {
        $captured = 0;
        $failed = 0;
        $this->awaitingSnapshots()->select('id')->chunkById(100, function ($polls) use (&$captured, &$failed): void {
            foreach ($polls as $poll) {
                try {
                    $snapshot = $this->finalizeExpiredPoll($poll->id);
                    if ($snapshot?->wasRecentlyCreated) {
                        $captured++;
                    }
                } catch (Throwable $error) {
                    $failed++;
                    Log::error('Poll result finalization failed', ['poll_id' => $poll->id]);
                    report($error);
                }
            }
        });

        return ['captured' => $captured, 'failed' => $failed];
    }
}
