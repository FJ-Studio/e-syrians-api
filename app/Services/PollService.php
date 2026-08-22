<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use App\Models\User;
use App\Models\PollVote;
use App\Models\PollOption;
use InvalidArgumentException;
use App\Enums\RevealResultsEnum;
use App\Models\PollAudienceRule;
use Illuminate\Support\Facades\DB;
use App\Jobs\LogPollVoteToBigQuery;
use Illuminate\Support\Facades\Date;
use App\Contracts\PollServiceContract;
use App\Exceptions\PollVotingException;
use App\Exceptions\PollReactionException;
use Illuminate\Database\Eloquent\Builder;
use App\Contracts\AudienceServiceContract;
use App\Jobs\SyncPollAudienceRulesToBigQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PollService implements PollServiceContract
{
    public function __construct(
        private readonly AudienceServiceContract $audiences,
    ) {
    }

    /**
     * Translate a poll payload's `audience_uuid` (client-facing) into
     * the internal `audience_id` used by the polls table. Returns
     * `null` when the caller didn't send the field. Distinguishing
     * "field absent" from "field explicitly cleared" is the caller's
     * job (via array_key_exists on the original payload).
     */
    private function resolveAudienceIdFromPayload(array $data, int $userId): ?int
    {
        if (! array_key_exists('audience_uuid', $data) || $data['audience_uuid'] === null) {
            return null;
        }

        // FormRequest already verified ownership, but resolving again
        // here keeps the service safe to call from other entry points
        // (jobs, tinker, tests) without leaking the responsibility.
        return $this->audiences->resolveOwnedUuidToId((string) $data['audience_uuid'], $userId);
    }

    /**
     * The legacy pasted-list payload was replaced by reusable
     * audiences. Keep this guard at the service boundary so non-HTTP
     * callers fail loudly instead of silently writing unsupported
     * audience state.
     */
    private function rejectLegacyAllowedVoters(array $data): void
    {
        if (array_key_exists('allowed_voters', $data)) {
            throw new InvalidArgumentException('allowed_voters_no_longer_supported');
        }
    }

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
        /** @var Poll $poll */
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
        $this->rejectLegacyAllowedVoters($data);

        return DB::transaction(function () use ($data, $userId) {
            // BUG FIX (2026-06-22): `end_date` was previously
            // computed from `now()` instead of the start date, so
            // a poll created on Apr 8 with start=Apr 30 + duration=5
            // ended on Apr 13 (= now + 5) instead of May 5
            // (= start + 5). The frontend (web table, mobile My
            // Polls card) faithfully renders whatever the backend
            // saves, which is why "Apr 30 → Apr 13" showed up
            // there. End date must always be derived from the
            // user-specified start, not the request timestamp.
            $startDate = Date::parse($data['start_date']);
            $poll = new Poll([
                'question' => $data['question'],
                'start_date' => $startDate,
                'end_date' => $startDate->copy()->addDays((int) ($data['duration'])),
                'max_selections' => $data['max_selections'],
                'audience_can_add_options' => $data['audience_can_add_options'],
                'reveal_results' => $data['reveal_results'],
                'voters_are_visible' => $data['voters_are_visible'],
                'audience_only' => $data['audience_only'] ?? false,
                // Optional reference to a reusable Audience —
                // client sends `audience_uuid`, we resolve to id.
                'audience_id' => $this->resolveAudienceIdFromPayload($data, $userId),
            ]);
            $poll->created_by = $userId;
            $poll->save();

            // Insert audience rules into normalized table.
            //
            // Skip when a reusable audience list drives this poll —
            // the two audience mechanisms are mutually exclusive
            // (see StorePollRequest::withValidator). Storing stale
            // demographic rules alongside an audience_id would make
            // the API display one audience
            // while the vote check enforces another.
            if ($poll->audience_id === null) {
                $this->insertAudienceRules($poll, $data);
            }

            $options = collect($data['options'])->map(fn ($option) => [
                'poll_id' => $poll->id,
                'option_text' => $option,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            PollOption::insert($options->all());

            // Sync audience rules to BigQuery for fraud detection
            dispatch(new SyncPollAudienceRulesToBigQuery($poll->id));

            return $poll;
        });
    }

    /**
     * Update an existing poll. Edits are only legal while the
     * poll has zero votes — the controller / FormRequest enforce
     * that. This method re-checks the gate INSIDE a transaction
     * with a SELECT … FOR UPDATE on the poll row, which serialises
     * against `vote()` (which takes the same lock). That closes
     * the race where a vote was in flight when the FormRequest
     * authorised the edit, and lands while we're soft-deleting
     * the options it references — without the lock the new vote
     * could end up pointing at a soft-deleted poll_option_id (no
     * FK to catch it), corrupting the option totals.
     */
    public function updatePoll(Poll $poll, array $data): Poll
    {
        $this->rejectLegacyAllowedVoters($data);

        return DB::transaction(function () use ($poll, $data) {
            // Re-acquire the poll WITH a row-level write lock.
            // Concurrent vote() calls also lockForUpdate the poll
            // row, so this serialises edits vs votes against the
            // same poll. A vote that won the race lands first and
            // gets reflected in the count check below.
            //
            // `withoutGlobalScope('public_polls')` is required: the
            // route binding handed us a private poll the owner is
            // editing, but the default scope would filter
            // `is_private = false` and the firstOrFail would 404
            // mid-transaction.
            $poll = Poll::withoutGlobalScope('public_polls')
                ->whereKey($poll->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Inside the locked region: re-check the vote-lock.
            // FormRequest::authorize already ran this once, but a
            // vote could have committed between authorize() and
            // this transaction starting. Throwing here surfaces
            // the same UX message clients already handle.
            if ($poll->votes()->exists()) {
                throw new PollVotingException('poll_has_votes_cannot_edit', 403);
            }

            // Scalar-field updates — only touch fields the client
            // actually sent. The array_intersect_key dance lets a
            // caller PATCH just `question` without us nulling out
            // unrelated columns.
            $editable = [
                'question',
                'max_selections',
                'audience_can_add_options',
                'reveal_results',
                'voters_are_visible',
                'audience_only',
            ];
            $patch = array_intersect_key($data, array_flip($editable));

            // audience_uuid — resolve to id here rather than in the
            // rules array because the payload key ("audience_uuid")
            // differs from the column name ("audience_id"), and
            // because a null uuid means "clear" (needs distinguishing
            // from "not sent").
            $audienceChanged = array_key_exists('audience_uuid', $data);
            if ($audienceChanged) {
                $patch['audience_id'] = $data['audience_uuid'] === null
                    ? null
                    : $this->resolveAudienceIdFromPayload($data, (int) $poll->created_by);
            }

            // start_date / duration → recompute end_date the same
            // way createPoll does (the bug-fixed `start + duration`
            // formula, not `now + duration`). Either field on its
            // own triggers the recompute using the other side's
            // existing value.
            if (array_key_exists('start_date', $data) || array_key_exists('duration', $data)) {
                $startDate = Date::parse(
                    $data['start_date'] ?? $poll->start_date->toDateString()
                );
                $duration = (int) ($data['duration'] ?? $startDate->diffInDays($poll->end_date));
                $patch['start_date'] = $startDate;
                $patch['end_date'] = $startDate->copy()->addDays($duration);
            }

            if ($patch !== []) {
                $poll->fill($patch)->save();
            }

            // Options — full replace. Soft-delete to preserve the
            // audit trail since PollOption uses SoftDeletes; the
            // unique constraint on (poll_id, option_text) is
            // tolerant of soft-deleted rows.
            if (array_key_exists('options', $data)) {
                $poll->options()->delete();
                $now = now();
                $rows = collect($data['options'])->map(fn ($text) => [
                    'poll_id' => $poll->id,
                    'option_text' => $text,
                    'created_by' => $poll->created_by,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                PollOption::insert($rows->all());
            }

            // Audience rules — full replace.
            //
            // Trigger rebuild when:
            //   • any inline demographic field was sent, OR
            //   • audience_uuid itself changed (attaching a reusable
            //     audience needs to WIPE the inline rules so both
            //     sources of truth don't disagree; detaching needs a
            //     rebuild-from-empty for the same reason).
            //
            // When the poll ends up backed by a reusable audience_id
            // we intentionally clear the PollAudienceRule rows and
            // don't re-insert — the two audience mechanisms are
            // mutually exclusive. See StorePollRequest::withValidator
            // for the payload-level enforcement.
            $audienceKeys = [
                'gender', 'min_age', 'max_age', 'country',
                'religious_affiliation', 'hometown', 'ethnicity',
                'province',
            ];
            $anyAudienceChange = count(array_intersect(array_keys($data), $audienceKeys)) > 0;
            if ($anyAudienceChange || $audienceChanged) {
                PollAudienceRule::where('poll_id', $poll->id)->delete();

                // Resolve the effective audience_id AFTER this PATCH:
                // if the client sent `audience_uuid`, honour the new
                // value (including an explicit null = detach); else
                // fall back to whatever the poll already had.
                // `??` was wrong here because null on the left is
                // exactly the detach signal we mustn't lose.
                $effectiveAudienceId = array_key_exists('audience_id', $patch)
                    ? $patch['audience_id']
                    : $poll->audience_id;

                if ($effectiveAudienceId === null) {
                    $this->insertAudienceRules($poll, $data);
                }
                dispatch(new SyncPollAudienceRulesToBigQuery($poll->id));
            }

            return $poll->refresh();
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
        // Wrap vote validation + insert in a transaction with a
        // SELECT … FOR UPDATE on the poll row. This serialises
        // votes against updatePoll() (which takes the same lock)
        // so a vote can't reference an option that's about to be
        // soft-deleted by a concurrent edit. PollOption uses
        // SoftDeletes + has no FK from poll_votes.poll_option_id,
        // so without this lock a vote could persist a poll_option_id
        // that the edit then soft-deletes — corrupting the option
        // totals on the poll detail screen.
        DB::transaction(function () use ($pollId, $optionIds, $userId): void {
            /** @var Poll $poll */
            $poll = Poll::whereKey($pollId)->lockForUpdate()->firstOrFail();
            $poll->load('audienceRules');

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

            // Validate options belong to the poll. SoftDeletes
            // automatically excludes deleted rows from the count;
            // since updatePoll() holds the same row lock, any
            // option-replace it performs has either committed
            // before us (and we see the new options) or is
            // waiting on our lock to release.
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
        });

        // Mirror vote to BigQuery for fraud detection — outside the
        // transaction so a failed dispatch doesn't roll back the
        // vote (the queue worker can retry independently).
        $request = request();
        dispatch(new LogPollVoteToBigQuery($userId, $pollId, $optionIds, $request->ip(), $request->userAgent()));
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

        $arrayCriteria = [
            'gender',
            'country',
            'religious_affiliation',
            'hometown',
            'ethnicity',
            'province',
        ];

        foreach ($arrayCriteria as $criterion) {
            $values = array_values(array_unique(array_filter(
                array_map('strval', $data[$criterion] ?? []),
                fn (string $v): bool => $v !== '',
            )));

            foreach ($values as $value) {
                $rules[] = [
                    'poll_id' => $poll->id,
                    'criterion' => $criterion,
                    'value' => $value,
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

        if (count($rules) > 0) {
            PollAudienceRule::insert($rules);
        }
    }

    /**
     * Build the common poll query with user interaction data.
     *
     * @return Builder<Poll>
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
