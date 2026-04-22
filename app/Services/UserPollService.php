<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Contracts\UserPollServiceContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserPollService implements UserPollServiceContract
{
    public function getUserPolls(User $user, int $perPage = 25): LengthAwarePaginator
    {
        return $user->polls()
            ->withTrashed()
            ->withCount('votes')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getUserReactions(User $user, int $perPage = 25): LengthAwarePaginator
    {
        return $user->reactions()
            ->whereHas('poll', function ($query): void {
                $query->whereNull('deleted_at');
            })
            ->with(['poll:id,question'])
            ->paginate($perPage);
    }

    public function getUserVotes(User $user, int $page = 1, int $perPage = 25): array
    {
        $userVotes = $user->votes()
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

        $total = $userVotes->count();

        return [
            'data' => $userVotes->forPage($page, $perPage)->values(),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }
}
