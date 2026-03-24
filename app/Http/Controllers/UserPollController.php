<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class UserPollController extends Controller
{
    public function myPolls(Request $request): JsonResponse
    {
        $user = $request->user();
        $polls = $user->polls()
            ->withTrashed()
            ->withCount('votes')
            ->paginate(25);

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
        $reactions = $request->user()->reactions()
            ->whereHas('poll', function ($query) {
                $query->whereNull('deleted_at');
            })
            ->with(['poll:id,question'])
            ->paginate(25);

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
        $perPage = (int) $request->query('per_page', 25);
        $page = (int) $request->query('page', 1);

        $userVotes = $request->user()->votes()
            ->with('option.poll')
            ->get()
            ->groupBy('option.poll_id')
            ->map(function ($votes) {
                $firstVote = $votes->first();
                $option = $firstVote->option ?? null;

                if (! $option || ! $option->poll) {
                    return null;
                }

                $poll = $option->poll;

                return [
                    'poll_id' => $poll->id,
                    'question' => $poll->question,
                    'selected_options' => $votes->pluck('option.option_text'),
                    'created_at' => $firstVote->created_at,
                ];
            })
            ->filter()
            ->values();

        $paginatedVotes = new LengthAwarePaginator(
            $userVotes->forPage($page, $perPage),
            $userVotes->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return ApiService::success($paginatedVotes);
    }
}
