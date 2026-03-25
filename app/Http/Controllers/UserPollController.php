<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\UserPollServiceContract;
use App\Services\ApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPollController extends Controller
{
    public function __construct(
        private readonly UserPollServiceContract $userPollService,
    ) {}

    public function myPolls(Request $request): JsonResponse
    {
        $polls = $this->userPollService->getUserPolls($request->user());

        return ApiService::success([
            'polls' => $polls->items(),
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
