<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Exception;
use Throwable;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\PollResource;
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

        $polls = $this->pollService->getPaginatedPolls(
            (int) $request->input('year', now()->year),
            (int) $request->input('month', now()->month),
            $userId,
        );

        return ApiService::success([
            'polls' => PollResource::collection($polls->items()),
            'current_page' => $polls->currentPage(),
            'last_page' => $polls->lastPage(),
            'per_page' => $polls->perPage(),
            'total' => $polls->total(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $userId = auth('sanctum')->user()?->id;

        $poll = $this->pollService->getPollById($id, $userId);

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
