<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PollServiceContract;
use App\Enums\RevealResultsEnum;
use App\Exceptions\PollReactionException;
use App\Exceptions\PollVotingException;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PollService implements PollServiceContract
{
    public function getPaginatedPolls(int $year, int $month, ?int $userId): LengthAwarePaginator
    {
        return $this->buildPollQuery($userId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderByRaw('(ups_count - downs_count) DESC')
            ->paginate(50);
    }

    public function getPollById(int $id, ?int $userId): Poll
    {
        return $this->buildPollQuery($userId)
            ->withoutGlobalScope('public_polls')
            ->findOrFail($id);
    }

    public function createPoll(array $data, int $userId): Poll
    {
        return DB::transaction(function () use ($data, $userId) {
            $audience = [
                'gender' => $data['gender'] ?? [],
                'age_range' => [
                    'min' => $data['min_age'] ?? 13,
                    'max' => $data['max_age'] ?? 120,
                ],
                'country' => $data['country'] ?? [],
                'religious_affiliation' => $data['religious_affiliation'] ?? [],
                'hometown' => $data['hometown'] ?? [],
                'ethnicity' => $data['ethnicity'] ?? [],
            ];

            $poll = new Poll([
                'question' => $data['question'],
                'start_date' => $data['start_date'],
                'end_date' => now()->addDays((int) ($data['duration'])),
                'max_selections' => $data['max_selections'],
                'audience_can_add_options' => $data['audience_can_add_options'],
                'reveal_results' => $data['reveal_results'],
                'voters_are_visible' => $data['voters_are_visible'],
                'audience' => $audience,
            ]);
            $poll->created_by = $userId;
            $poll->save();

            $options = collect($data['options'])->map(fn ($option) => [
                'poll_id' => $poll->id,
                'option_text' => $option,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            PollOption::insert($options->toArray());

            return $poll;
        });
    }

    public function toggleStatus(int $pollId): void
    {
        $poll = Poll::withTrashed()->findOrFail($pollId);

        if ($poll->trashed()) {
            $poll->restore();
        } else {
            $poll->delete();
        }
    }

    public function vote(int $pollId, array $optionIds, int $userId): void
    {
        $poll = Poll::findOrFail($pollId);

        if ($poll->start_date->isFuture()) {
            throw new PollVotingException(__('api.poll_has_not_started_yet'));
        }

        if ($poll->end_date->isPast()) {
            throw new PollVotingException(__('api.poll_has_expired'));
        }

        if ($poll->votes()->where('user_id', $userId)->exists()) {
            throw new PollVotingException(__('api.you_have_already_voted'));
        }

        // Audience eligibility check
        $user = User::findOrFail($userId);
        $inAudience = $user->isInAudience($poll->audience);
        if (! $inAudience[0]) {
            throw new PollVotingException(__('api.user_is_not_in_poll_audience'));
        }

        if (count($optionIds) > $poll->max_selections) {
            throw new PollVotingException(__('api.user_has_reached_the_max_selections'));
        }

        // Validate options belong to the poll
        $validOptions = PollOption::whereIn('id', $optionIds)
            ->where('poll_id', $poll->id)
            ->count();

        if ($validOptions !== count($optionIds)) {
            throw new PollVotingException(__('api.invalid_options'));
        }

        $poll->votes()->createMany(
            collect($optionIds)->map(fn ($optionId) => [
                'user_id' => $userId,
                'poll_option_id' => $optionId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray()
        );
    }

    public function react(int $pollId, string $reaction, int $userId): void
    {
        $poll = Poll::findOrFail($pollId);

        if ($poll->start_date->isFuture()) {
            throw new PollReactionException(__('api.poll_has_not_started_yet'));
        }

        if ($poll->end_date->isPast()) {
            throw new PollReactionException(__('api.poll_has_expired'));
        }

        // Remove previous reaction and add new one
        $poll->reactions()->where('user_id', $userId)->delete();

        $poll->reactions()->create([
            'user_id' => $userId,
            'reaction' => $reaction,
        ]);
    }

    public function shouldRevealResults(Poll $poll, ?User $user): bool
    {
        if ($poll->reveal_results === RevealResultsEnum::BeforeVoting->value) {
            return true;
        }

        if ($poll->reveal_results === RevealResultsEnum::AfterExpiration->value) {
            return now()->isAfter($poll->end_date);
        }

        if (! $user) {
            return false;
        }

        if ($poll->reveal_results === RevealResultsEnum::AfterVoting->value) {
            return $poll->votes()->where('user_id', $user->id)->exists();
        }

        return false;
    }

    /**
     * Build the common poll query with user interaction data
     * (Eliminates the duplication between index() and show())
     */
    private function buildPollQuery(?int $userId): \Illuminate\Database\Eloquent\Builder
    {
        return Poll::with(['user', 'options' => fn ($q) => $q->withCount('votes')])
            ->withCount([
                'ups as ups_count',
                'downs as downs_count',
                'votes as total_votes',
            ])
            ->selectSub(
                PollVote::selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('poll_id', 'polls.id'),
                'unique_voters_count'
            )
            ->when((bool) $userId, function ($query) use ($userId) {
                $query->withExists([
                    'votes as has_voted' => fn ($q) => $q->where('user_id', $userId),
                    'ups as has_upvoted' => fn ($q) => $q->where('user_id', $userId),
                    'downs as has_downvoted' => fn ($q) => $q->where('user_id', $userId),
                ]);

                $query->with([
                    'votes' => fn ($q) => $q->where('user_id', $userId),
                ]);
            });
    }
}
