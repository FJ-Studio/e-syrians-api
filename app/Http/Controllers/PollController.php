<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\PollServiceContract;
use App\Exceptions\PollReactionException;
use App\Exceptions\PollVotingException;
use App\Http\Requests\Polls\StorePollReaction;
use App\Http\Requests\Polls\StorePollRequest;
use App\Http\Requests\Polls\StorePollVoteRequest;
use App\Http\Resources\PollResource;
use App\Services\ApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PollController extends Controller
{
    public function __construct(
        private readonly PollServiceContract $pollService,
    ) {}

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
                Auth::id(),
            );

            return ApiService::success(new PollResource($poll));
        } catch (\Throwable $e) {
            Log::error('Poll creation failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return ApiService::error(500);
        }
    }

    public function status(Request $request, int $pollId): JsonResponse
    {
        try {
            $this->pollService->toggleStatus($pollId);

            return ApiService::success([]);
        } catch (\Exception $e) {
            return ApiService::error(500, $e->getMessage());
        }
    }

    public function vote(StorePollVoteRequest $request): JsonResponse
    {
        try {
            $this->pollService->vote(
                $request->poll_id,
                $request->poll_option_id,
                Auth::id(),
            );

            return ApiService::success([]);
        } catch (PollVotingException $e) {
            return ApiService::error($e->getCode(), $e->getMessage());
        }
    }

    public function react(StorePollReaction $request): JsonResponse
    {
        try {
            $this->pollService->react(
                $request->poll_id,
                $request->reaction,
                Auth::id(),
            );

            return ApiService::success([]);
        } catch (PollReactionException $e) {
            return ApiService::error($e->getCode(), $e->getMessage());
        }
    }
}
