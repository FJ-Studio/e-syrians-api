<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\PollResource;
use App\Contracts\UserPollServiceContract;

class UserPollController extends Controller
{
    public function __construct(
        private readonly UserPollServiceContract $userPollService,
    ) {
    }

    public function myPolls(Request $request): JsonResponse
    {
        // Wrap in PollResource so the My Polls screen receives the
        // same shape as /polls (status pill data, audience flags,
        // unique_voters_count, deleted_at, etc.). Previously this
        // returned raw model rows which forced the client to guess
        // at field names.
        $polls = $this->userPollService->getUserPolls($request->user());

        return ApiService::success([
            'polls' => PollResource::collection($polls->items()),
            'total' => $polls->total(),
            'per_page' => $polls->perPage(),
            'current_page' => $polls->currentPage(),
            'last_page' => $polls->lastPage(),
        ]);
    }

    public function myReactions(Request $request): JsonResponse
    {
        $reactions = $this->userPollService->getUserReactions($request->user());

        return ApiService::success([
            'reactions' => $reactions->items(),
            'total' => $reactions->total(),
            'per_page' => $reactions->perPage(),
            'current_page' => $reactions->currentPage(),
            'last_page' => $reactions->lastPage(),
        ]);
    }

    public function myVotes(Request $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 25);

        $votes = $this->userPollService->getUserVotes($request->user(), $page, $perPage);

        return ApiService::success($votes);
    }
}
