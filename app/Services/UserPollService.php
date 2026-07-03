<?php

declare(strict_types=1);

namespace App\Services;

use stdClass;
use App\Models\User;
use App\Models\PollVote;
use Illuminate\Support\Facades\DB;
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
        // Two-query implementation. Previously this method ->get()
        // the user's entire vote history into memory, ->groupBy()
        // by poll_id in PHP, then ->forPage() to slice — so every
        // page request triggered a full vote-table scan.
        //
        // First cut at the fix used a single grouped query with
        // GROUP_CONCAT(option_text), which broke the test suite
        // (SQLite has no `SEPARATOR` keyword), risked silent
        // truncation under MySQL's default group_concat_max_len,
        // and bypassed PollOption's SoftDeletes scope because the
        // raw join doesn't respect Eloquent global scopes.
        //
        // Current shape: paginate the distinct polls the user
        // voted on (portable SQL — `GROUP BY` + LIMIT/OFFSET via
        // Laravel's paginate), then fetch the actual option texts
        // for just those polls in a second Eloquent query that
        // routes through the `option` relation so PollOption's
        // SoftDeletes scope applies for free.
        //
        // Soft-delete semantics are now explicit + match the old
        // Eloquent-relation path: query 1 filters out votes whose
        // option OR poll has been soft-deleted (so a poll where
        // every voted option was deleted disappears), and we also
        // skip private polls (the old code did too, via the
        // public_polls global scope being applied to the
        // relation load).
        // Query 1 uses DB::table (not Eloquent) so each row is a
        // generic \stdClass and PHPStan doesn't trip on the
        // dynamic property access for the projected columns —
        // we don't need any model methods here, this is a pure
        // aggregate read.
        $pollPage = DB::table('poll_votes')
            ->join('poll_options', 'poll_votes.poll_option_id', '=', 'poll_options.id')
            ->join('polls', 'poll_votes.poll_id', '=', 'polls.id')
            ->where('poll_votes.user_id', $user->id)
            ->whereNull('poll_options.deleted_at')
            ->whereNull('polls.deleted_at')
            ->where('polls.is_private', false)
            ->select([
                'polls.id as poll_id',
                'polls.question as question',
                DB::raw('MIN(poll_votes.created_at) as voted_at'),
            ])
            ->groupBy('polls.id', 'polls.question')
            ->orderByRaw('MIN(poll_votes.created_at) DESC')
            ->paginate($perPage, ['*'], 'page', $page);

        $pollIds = array_map(
            static fn (stdClass $row): int => (int) $row->poll_id,
            $pollPage->items(),
        );

        // Query 2: option texts for this page's polls. Also via
        // DB::table — the earlier draft used
        // `PollVote::query()->with('option' => …)` but Larastan
        // can't follow the `option` relation off the PollVote
        // model and complained both that the relation didn't
        // exist and that `$vote->option` was an undefined
        // property. The raw join is simpler anyway: the Eloquent
        // path only mattered because it inherited PollOption's
        // SoftDeletes scope; we now make that filter explicit
        // with `whereNull('poll_options.deleted_at')`, matching
        // the same semantics without any model property access.
        /** @var array<int, array<int, string>> $optionsByPoll */
        $optionsByPoll = [];

        if ($pollIds !== []) {
            $optionRows = DB::table('poll_votes')
                ->join('poll_options', 'poll_votes.poll_option_id', '=', 'poll_options.id')
                ->where('poll_votes.user_id', $user->id)
                ->whereIn('poll_votes.poll_id', $pollIds)
                ->whereNull('poll_options.deleted_at')
                ->orderBy('poll_votes.poll_id')
                ->orderBy('poll_votes.id')
                ->select(['poll_votes.poll_id', 'poll_options.option_text'])
                ->get();

            foreach ($optionRows as $row) {
                /** @var stdClass $row */
                $optionsByPoll[(int) $row->poll_id][] = (string) $row->option_text;
            }
        }

        // Wrap items in a Collection so the return shape matches
        // the contract's `data: Collection` slot.
        $items = collect(array_map(
            static fn (stdClass $row): array => [
                'poll_id' => (int) $row->poll_id,
                'question' => (string) $row->question,
                'selected_options' => $optionsByPoll[(int) $row->poll_id] ?? [],
                'created_at' => $row->voted_at,
            ],
            $pollPage->items(),
        ));

        return [
            'data' => $items,
            'total' => $pollPage->total(),
            'per_page' => $pollPage->perPage(),
            'current_page' => $pollPage->currentPage(),
            'last_page' => $pollPage->lastPage(),
        ];
    }
}
