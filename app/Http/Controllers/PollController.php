<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Exception;
use Throwable;
use App\Models\Poll;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\PollResource;
use Illuminate\Support\Facades\Cache;
use App\Contracts\PollServiceContract;
use App\Exceptions\PollVotingException;
use App\Exceptions\PollReactionException;
use App\Contracts\FileUploadServiceContract;
use App\Http\Requests\Polls\StorePollRequest;
use App\Http\Requests\Polls\StorePollReaction;
use App\Http\Requests\Polls\UpdatePollRequest;
use App\Http\Requests\Polls\StorePollVoteRequest;

class PollController extends Controller
{
    public function __construct(
        private readonly PollServiceContract $pollService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $userId = auth('sanctum')->check() ? auth('sanctum')->user()->id : null;

        $result = $this->pollService->getPaginatedPolls(
            (int) $request->input('year', now()->year),
            (int) $request->input('month', now()->month),
            $userId,
        );

        $polls = $result['polls'];

        return ApiService::success([
            'polls' => PollResource::collection($polls->items()),
            'current_page' => $polls->currentPage(),
            'last_page' => $polls->lastPage(),
            'per_page' => $polls->perPage(),
            'total' => $polls->total(),
            'audience_only_count' => $result['audience_only_count'],
        ]);
    }

    public function show(Poll $poll): JsonResponse
    {
        // The route param is `{poll}`, which AppServiceProvider's
        // `Route::bind` resolves to a Poll without the
        // `public_polls` global scope (and 404s non-creators
        // attempting to view someone else's private poll). We
        // re-fetch via `getPollById` because the binding only
        // returns the bare model — getPollById enriches it with
        // the withCount/withExists/relationship loading the
        // PollResource depends on, and it computes `is_restricted`
        // for audience-only polls.
        $userId = auth('sanctum')->user()?->id;
        $poll = $this->pollService->getPollById($poll->id, $userId);

        if ($poll->is_restricted) {
            return ApiService::error(403, 'poll_visible_to_targeted_audience_only');
        }

        return ApiService::success(new PollResource($poll));
    }

    public function store(StorePollRequest $request): JsonResponse
    {
        try {
            $poll = $this->pollService->createPoll(
                $request->validated(),
                $request->user()->id,
            );

            return ApiService::success(new PollResource($poll));
        } catch (Throwable $e) {
            Log::error('Poll creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return ApiService::error(500);
        }
    }

    /**
     * Creator-only edit payload — the data the edit form needs to
     * hydrate.
     *
     * Why this exists separately from `show()`: the public show
     * endpoint can be inaccessible for audience-only polls when the
     * owner no longer matches the current audience criteria. The edit
     * surface is creator-owned, so it needs a direct ownership-gated
     * read path.
     *
     * Gates: auth (sanctum guard via route group) + ownership
     * check below. We don't apply the vote-lock here because the
     * edit form should still render (so the user sees what they
     * created) — the UpdatePollRequest::authorize is the
     * authoritative gate when they hit Save.
     */
    public function editPayload(Request $request, Poll $poll): JsonResponse
    {
        if ($poll->created_by !== $request->user()->id) {
            return ApiService::error(403, 'not_your_poll');
        }

        // Re-fetch through the service so the resource has the
        // same eager-loaded relationships and computed columns
        // (withCount, withExists, unique_voters_count) it does on
        // the public show endpoint. The bare bound model lacks
        // those.
        $enriched = $this->pollService->getPollById(
            $poll->id,
            $request->user()->id,
        );

        return ApiService::success(new PollResource($enriched));
    }

    /**
     * Edit a poll the user created — only legal while the poll
     * has zero votes. UpdatePollRequest::authorize() handles both
     * the ownership and the vote-lock check; reaching this method
     * means we're cleared to write.
     */
    public function update(UpdatePollRequest $request, Poll $poll): JsonResponse
    {
        try {
            $updated = $this->pollService->updatePoll($poll, $request->validated());

            return ApiService::success(new PollResource($updated));
        } catch (PollVotingException $e) {
            // Vote-lock race: a vote committed between authorize()
            // and the updatePoll transaction lock. The service
            // throws `poll_has_votes_cannot_edit` with HTTP 403 —
            // surface it as such so the client toast translates
            // correctly instead of falling through to a generic 500.
            return ApiService::error($e->getCode(), [$e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Poll update failed', [
                'error' => $e->getMessage(),
                'poll_id' => $poll->id,
                'user_id' => $request->user()->id,
            ]);

            return ApiService::error(500);
        }
    }

    /**
     * Toggle a poll between active and soft-deleted (the
     * "Close/Reopen" action on My Polls). Ownership is enforced
     * here — without it any authenticated user could close any
     * other user's poll, which would be a destructive vulnerability
     * given the public delete-via-soft-delete semantics. We look
     * up the poll with `withTrashed()` because a reopen request
     * targets a soft-deleted row that the default scope would
     * exclude, and we deliberately bypass the public_polls global
     * scope so creators can still close their private polls.
     *
     * Returns the updated poll's `id` and a fresh `deleted_at`
     * value so the client can update its row state without
     * re-fetching the full list.
     */
    public function status(Request $request, int $pollId): JsonResponse
    {
        $poll = Poll::withTrashed()
            ->withoutGlobalScope('public_polls')
            ->find($pollId);

        if (! $poll) {
            return ApiService::error(404, 'poll_not_found');
        }

        if ($poll->created_by !== $request->user()->id) {
            return ApiService::error(403, 'not_your_poll');
        }

        try {
            $this->pollService->toggleStatus($pollId);

            // Re-fetch so we surface the updated trashed state to
            // the client (closed ↔ reopened).
            $fresh = Poll::withTrashed()
                ->withoutGlobalScope('public_polls')
                ->findOrFail($pollId);

            return ApiService::success([
                'id' => $fresh->id,
                'deleted_at' => $fresh->deleted_at?->toISOString(),
            ]);
        } catch (Exception $e) {
            return ApiService::error(500, $e->getMessage());
        }
    }

    public function vote(StorePollVoteRequest $request): JsonResponse
    {
        try {
            $this->pollService->vote(
                $request->poll_id,
                $request->poll_option_id,
                $request->user()->id,
            );

            return ApiService::success([]);
        } catch (PollVotingException $e) {
            $messages = $e->getDetails() ?: [$e->getMessage()];

            return ApiService::error($e->getCode(), $messages);
        }
    }

    public function optionVoters(Request $request): JsonResponse
    {
        $request->validate([
            'poll_option_id' => ['required', 'integer', 'exists:poll_options,id'],
        ]);

        try {
            $voters = $this->pollService->getOptionVoters(
                (int) $request->input('poll_option_id'),
            );

            $fileService = resolve(FileUploadServiceContract::class);

            $data = collect($voters->items())->map(function ($vote) use ($fileService) {
                $user = $vote->user;
                $avatarUrl = null;
                if ($user->avatar) {
                    try {
                        $avatarUrl = $fileService->temporaryUrl(
                            $user->avatar,
                            (int) config('e-syrians.files.avatar.ttl', 60),
                        );
                    } catch (Exception $e) {
                        $avatarUrl = null;
                    }
                }

                return [
                    'id' => $user->uuid,
                    'name' => $user->name,
                    'surname' => $user->surname,
                    'avatar' => $avatarUrl,
                ];
            });

            return ApiService::success([
                'data' => $data,
                'current_page' => $voters->currentPage(),
                'last_page' => $voters->lastPage(),
                'total' => $voters->total(),
            ]);
        } catch (Exception $e) {
            return ApiService::error(403, $e->getMessage());
        }
    }

    /**
     * Return the audience criteria for a poll.
     *
     * Polls are immutable while they have votes (and the dedicated
     * creator-only edit endpoint — TBD — only operates on vote-less
     * polls), so the audience snapshot returned here is cached
     * indefinitely (until the cache store evicts it).
     *
     * Audience exposure rule:
     *   • Demographic criteria (gender / age / country / …) — exposed
     *     to every viewer so the audience-criteria sheet can render
     *     the actual targeting rules.
     *   • Saved reusable audience list — exposed as a small summary
     *     only (name + counts, no identifiers).
     */
    public function audience(Request $request): JsonResponse
    {
        $request->validate([
            'poll_id' => ['required', 'integer', 'exists:polls,id'],
        ]);

        $pollId = (int) $request->input('poll_id');

        // Cache-decision branch: the historical `rememberForever`
        // was safe because inline demographic rules on a poll are
        // effectively immutable after creation. Saved audiences
        // (`polls.audience_id`) are a LIVE reference — the owner
        // can rename the list or add/remove entries at any time,
        // and the vote gate honours those edits on the next vote
        // attempt. A stale forever-cached snapshot would show the
        // wrong name / entry counts and mislead the client.
        //
        // Rather than layer invalidation on top of AudienceService
        // writes (fragile: every mutation would need to remember
        // to bust the key across every referencing poll), we skip
        // the cache entirely for saved-audience polls. Latency
        // impact is small — the accessor already batches the
        // withCount aggregate — and consistency is worth more
        // here than the cache hit.
        $pollHasSavedAudience = Poll::query()
            ->whereKey($pollId)
            ->whereNotNull('audience_id')
            ->exists();

        $loadAudience = function () use ($pollId): array {
            $poll = Poll::with('audienceRules')->findOrFail($pollId);

            return $poll->audience;
        };

        // Cache-key is versioned (`v2`) so legacy entries populated
        // by the pre-refactor code — which stored the pasted
        // `allowed_voters` list as plaintext in the forever cache —
        // are effectively invalidated: `rememberForever` on a new
        // key ignores the old entry entirely. The old `poll:{id}:audience`
        // key will simply age out of Redis (or stay dormant on file
        // cache with no reader).
        $audience = $pollHasSavedAudience
            ? $loadAudience()
            : Cache::rememberForever("poll:v2:{$pollId}:audience", $loadAudience);

        // Defensive scrub. In addition to the cache-key version bump
        // above, strip `allowed_voters` from the response on the way
        // out so any residual cache entry (or a legacy poll whose
        // rules are still in `poll_audience_rules`) never leaks the
        // hand-picked invite list on the public endpoint. The
        // accessor + cache always return an array here, so no
        // `is_array` guard is needed — PHPStan flags it as an
        // already-narrowed type check.
        if (! empty($audience['allowed_voters'])) {
            $audience['allowed_voters'] = [];
        }

        return ApiService::success($audience);
    }

    public function react(StorePollReaction $request): JsonResponse
    {
        try {
            $this->pollService->react(
                $request->poll_id,
                $request->reaction,
                $request->user()->id,
            );

            return ApiService::success([]);
        } catch (PollReactionException $e) {
            return ApiService::error($e->getCode(), $e->getMessage());
        }
    }
}
