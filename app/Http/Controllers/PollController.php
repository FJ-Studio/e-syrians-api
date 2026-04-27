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

    public function status(Request $request, int $pollId): JsonResponse
    {
        try {
            $this->pollService->toggleStatus($pollId);

            return ApiService::success([]);
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
     * Since polls are not editable after creation, the result is cached
     * indefinitely (until the cache store evicts it).
     *
     * When the audience uses an allowed-voters list, the actual voter
     * identifiers are only returned to the poll creator. Everyone else
     * receives an empty `allowed_voters` array so the frontend can
     * show a generic "invite-only" message without leaking PII.
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

        // Hide the actual allowed-voters list from non-creators.
        // The route is public (no auth middleware), so we attempt to
        // resolve the user via the sanctum guard manually.
        if (! empty($audience['allowed_voters'])) {
            $guard = auth('sanctum');
            // Trigger token resolution from the Authorization header.
            $user = $guard->user();
            $poll = Poll::select('id', 'created_by')->find($pollId);
            $isCreator = $user !== null
                && $poll !== null
                && (int) $user->getAuthIdentifier() === (int) $poll->created_by;

            if (! $isCreator) {
                $audience['allowed_voters'] = [];
            }
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
