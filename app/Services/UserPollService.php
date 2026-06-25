<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\PollVote;
use App\Contracts\UserPollServiceContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserPollService implements UserPollServiceContract
{
    public function getUserPolls(User $user, int $perPage = 25): LengthAwarePaginator
    {
        // Owner-scoped listing. Two scope skips are deliberate:
        //   - withTrashed(): the My Polls screen shows both active
        //     and closed (soft-deleted) polls so the user can
        //     reopen them.
        //   - withoutGlobalScope('public_polls'): Poll::booted()
        //     hides `is_private = true` rows from every query by
        //     default. Without skipping it here, an owner could
        //     not see their own private polls in the My Polls list.
        //     Owners are always allowed to see their own polls
        //     regardless of visibility.
        // The PollResource downstream reads `unique_voters_count`
        // (distinct voters, not raw vote count). Mirror the
        // subselect from PollService::buildPollQuery so the
        // resource doesn't fall through to `?? 0` and the My Polls
        // card can render the real number. `withCount('votes')`
        // alone produces `votes_count` (raw votes) — kept here
        // because some downstream consumers still read it, but the
        // resource specifically wants the distinct count.
        return $user->polls()
            ->withoutGlobalScope('public_polls')
            ->withTrashed()
            ->withCount('votes')
            ->selectSub(
                PollVote::selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('poll_id', 'polls.id'),
                'unique_voters_count'
            )
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getUserReactions(User $user, int $perPage = 25): LengthAwarePaginator
    {
        return $user->reactions()
            ->whereHas('poll', function ($query): void {
                $query->whereNull('deleted_at');
            })
            ->with(['poll:id,question'])
            ->paginate($perPage);
    }

    public function getUserVotes(User $user, int $page = 1, int $perPage = 25): array
    {
        $userVotes = $user->votes()
            ->with('option.poll')
            ->get()
            ->groupBy('option.poll_id')
            ->map(function ($votes) {
                $firstVote = $votes->first();
                $option = $firstVote->option ?? null;

                if (! $option || ! $option->poll) {
                    return null;
                }

                $poll = $option->poll;

                return [
                    'poll_id' => $poll->id,
                    'question' => $poll->question,
                    'selected_options' => $votes->pluck('option.option_text'),
                    'created_at' => $firstVote->created_at,
                ];
            })
            ->filter()
            ->values();

        $total = $userVotes->count();

        return [
            'data' => $userVotes->forPage($page, $perPage)->values(),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }
}
