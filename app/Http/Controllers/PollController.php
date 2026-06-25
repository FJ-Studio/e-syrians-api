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

    public function show(int $id): JsonResponse
    {
        $userId = auth('sanctum')->user()?->id;

        $poll = $this->pollService->getPollById($id, $userId);

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
     * Audience exposure rule (tightened 2026-06):
     *   • Demographic criteria (gender / age / country / …) — exposed
     *     to every viewer so the audience-criteria sheet can render
     *     the actual targeting rules.
     *   • Explicit-list audience (`allowed_voters`) — never exposed
     *     via this endpoint, not even to the creator. The list is a
     *     hand-picked guest list of voter identifiers; surfacing it
     *     here would leak who else was invited. The creator only
     *     needs the list when editing the poll, which goes through a
     *     dedicated creator-only edit endpoint (TBD); the cache still
     *     holds the full audience array internally, but every
     *     response strips `allowed_voters` to an empty list before
     *     sending. Non-creators learn membership via the boolean
     *     `is_in_audience` / `audience_failures` fields on
     *     /polls/{id}, not this endpoint.
     */
    public function audience(Request $request): JsonResponse
    {
        $request->validate([
            'poll_id' => ['required', 'integer', 'exists:polls,id'],
        ]);

        $pollId = (int) $request->input('poll_id');

        $audience = Cache::rememberForever("poll:{$pollId}:audience", function () use ($pollId) {
            $poll = Poll::with('audienceRules')->findOrFail($pollId);

            return $poll->audience;
        });

        // `allowed_voters` is the author's hand-picked invite list and is
        // never exposed via this public-audience endpoint — not even to
        // the creator. The creator only needs the list when editing the
        // poll, which goes through a dedicated creator-only edit
        // endpoint (TBD); the public surface uses the empty array as a
        // "this poll uses an invite list, but you don't get to see it"
        // signal. Non-creators learn membership via the boolean
        // `is_in_audience` / failure-code `audience_failures` returned
        // by /polls/{id}, not this endpoint.
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
