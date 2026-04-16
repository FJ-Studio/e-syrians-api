<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use App\Models\User;
use App\Models\PollVote;
use App\Models\PollOption;
use App\Enums\RevealResultsEnum;
use App\Models\PollAudienceRule;
use Illuminate\Support\Facades\DB;
use App\Contracts\PollServiceContract;
use App\Exceptions\PollVotingException;
use App\Exceptions\PollReactionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PollService implements PollServiceContract
{
    /**
     * Get paginated polls, filtering audience-only polls via SQL scope.
     *
     * @return array{polls: LengthAwarePaginator, audience_only_count: int}
     */
    public function getPaginatedPolls(int $year, int $month, ?int $userId): array
    {
        $user = $userId ? User::find($userId) : null;

        // Count audience-only polls hidden from this user in this period
        $totalAudienceOnly = Poll::where('audience_only', true)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count();

        $visibleAudienceOnly = Poll::where('audience_only', true)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->visibleTo($user)
            ->count();

        $audienceOnlyCount = max(0, $totalAudienceOnly - $visibleAudienceOnly);

        $polls = $this->buildPollQuery($userId)
            ->visibleTo($user)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderByRaw('(ups_count - downs_count) DESC')
            ->paginate(50);

        return [
            'polls' => $polls,
            'audience_only_count' => $audienceOnlyCount,
        ];
    }

    /**
     * Get a poll by ID. Returns the poll with an `is_restricted` flag
     * if the user is not in the audience of an audience-only poll.
     */
    public function getPollById(int $id, ?int $userId): Poll
    {
        $poll = $this->buildPollQuery($userId)
            ->with('audienceRules')
            ->withoutGlobalScope('public_polls')
            ->findOrFail($id);

        $user = $userId ? User::find($userId) : null;
        $poll->is_restricted = $poll->audience_only && ! $poll->isVisibleTo($user);

        return $poll;
    }

    public function createPoll(array $data, int $userId): Poll
    {
        return DB::transaction(function () use ($data, $userId) {
            $poll = new Poll([
                'question' => $data['question'],
                'start_date' => $data['start_date'],
                'end_date' => now()->addDays((int) ($data['duration'])),
                'max_selections' => $data['max_selections'],
                'audience_can_add_options' => $data['audience_can_add_options'],
                'reveal_results' => $data['reveal_results'],
                'voters_are_visible' => $data['voters_are_visible'],
                'audience_only' => $data['audience_only'] ?? false,
            ]);
            $poll->created_by = $userId;
            $poll->save();

            // Insert audience rules into normalized table
            $this->insertAudienceRules($poll, $data);

            $options = collect($data['options'])->map(fn ($option) => [
                'poll_id' => $poll->id,
                'option_text' => $option,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            PollOption::insert($options->all());

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
        $poll = Poll::with('audienceRules')->findOrFail($pollId);

        if ($poll->start_date->isFuture()) {
            throw new PollVotingException('poll_has_not_started_yet');
        }

        if ($poll->end_date->isPast()) {
            throw new PollVotingException('poll_has_expired');
        }

        if ($poll->votes()->where('user_id', $userId)->exists()) {
            throw new PollVotingException('you_have_already_voted');
        }

        // Audience eligibility check
        $user = User::findOrFail($userId);
        [$eligible, $failures] = $user->isInAudience($poll);
        if (! $eligible) {
            throw new PollVotingException('user_is_not_in_poll_audience', 400, $failures);
        }

        if (count($optionIds) > $poll->max_selections) {
            throw new PollVotingException('user_has_reached_the_max_selections');
        }

        // Validate options belong to the poll
        $validOptions = PollOption::whereIn('id', $optionIds)
            ->where('poll_id', $poll->id)
            ->count();

        if ($validOptions !== count($optionIds)) {
            throw new PollVotingException('invalid_options');
        }

        $poll->votes()->createMany(
            collect($optionIds)->map(fn ($optionId) => [
                'user_id' => $userId,
                'poll_option_id' => $optionId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all()
        );
    }

    public function react(int $pollId, string $reaction, int $userId): void
    {
        $poll = Poll::findOrFail($pollId);

        if ($poll->start_date->isFuture()) {
            throw new PollReactionException('poll_has_not_started_yet');
        }

        if ($poll->end_date->isPast()) {
            throw new PollReactionException('poll_has_expired');
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

    public function getOptionVoters(int $optionId, int $perPage = 20): LengthAwarePaginator
    {
        $option = PollOption::findOrFail($optionId);

        // Ensure the poll has voters_are_visible enabled
        $poll = $option->poll;
        if (! $poll->voters_are_visible) {
            abort(403, 'voters_not_visible');
        }

        return PollVote::where('poll_option_id', $optionId)
            ->with('user:id,uuid,name,surname,avatar')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Insert audience rules into the normalized table.
     */
    private function insertAudienceRules(Poll $poll, array $data): void
    {
        $rules = [];
        $now = now();
        $allowedVoters = $data['allowed_voters'] ?? [];

        if (count($allowedVoters) > 0) {
            foreach ($allowedVoters as $voter) {
                $rules[] = [
                    'poll_id' => $poll->id,
                    'criterion' => 'allowed_voter',
                    'value' => (string) $voter,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        } else {
            $arrayCriteria = [
                'gender',
                'country',
                'religious_affiliation',
                'hometown',
                'ethnicity',
                'city_inside_syria',
            ];

            foreach ($arrayCriteria as $criterion) {
                foreach ($data[$criterion] ?? [] as $value) {
                    $rules[] = [
                        'poll_id' => $poll->id,
                        'criterion' => $criterion,
                        'value' => (string) $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Age range — only store if not default
            $minAge = $data['min_age'] ?? null;
            $maxAge = $data['max_age'] ?? null;

            if ($minAge !== null && (int) $minAge !== 13) {
                $rules[] = [
                    'poll_id' => $poll->id,
                    'criterion' => 'age_min',
                    'value' => (string) $minAge,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($maxAge !== null && (int) $maxAge !== 120) {
                $rules[] = [
                    'poll_id' => $poll->id,
                    'criterion' => 'age_max',
                    'value' => (string) $maxAge,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (count($rules) > 0) {
            PollAudienceRule::insert($rules);
        }
    }

    /**
     * Build the common poll query with user interaction data.
     */
    private function buildPollQuery(?int $userId): Builder
    {
        return Poll::with(['user', 'audienceRules', 'options' => fn ($q) => $q->withCount('votes')])
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
            ->when((bool) $userId, function ($query) use ($userId): void {
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
