<?php

namespace App\Http\Controllers;

use App\Http\Requests\Polls\StorePollReaction;
use App\Http\Requests\Polls\StorePollRequest;
use App\Http\Requests\Polls\StorePollVoteRequest;
use App\Http\Resources\PollResource;
use App\Models\Poll;
use App\Models\PollOption;
use App\Services\ApiService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PollController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $userId = auth('sanctum')->user()?->id; // Use null-safe operator in case user is not logged in

        $polls = Poll::whereYear('created_at', $request->input('year', now()->year))
            ->whereMonth('created_at', $request->input('month', now()->month))
            ->with(['user', 'options'])
            ->withCount([
                'ups as ups_count',
                'downs as downs_count',
                'votes as total_votes' // Get total votes for percentage calculation
            ])
            ->when((bool)($userId), function ($query) use ($userId) {
                $query->withExists([
                    'votes as has_voted' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    },
                    'ups as has_upvoted' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    },
                    'downs as has_downvoted' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    }
                ]);

                // Load only the selected options if the user has voted
                $query->with([
                    'votes' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    }
                ]);
            })
            ->orderByRaw('(ups_count - downs_count) DESC')
            ->paginate(1);

        return ApiService::success([
            'polls' => PollResource::collection($polls->items()),
            'current_page' => $polls->currentPage(),
            'last_page' => $polls->lastPage(),
            'per_page' => $polls->perPage(),
            'total' => $polls->total(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePollRequest $request)
    {
        try {
            return DB::transaction(function () use ($request) {
                // Build Audience JSON
                $audience = [
                    'gender' => $request->input('gender', []),
                    'age_range' => [
                        'min' => $request->input('min_age', 13),
                        'max' => $request->input('max_age', 120),
                    ],
                    'country' => $request->input('country', []),
                    'religious_affiliation' => $request->input('religious_affiliation', []),
                    'hometown' => $request->input('hometown', []),
                    'ethnicity' => $request->input('ethnicity', []),
                ];

                // Create Poll
                $poll = Poll::create([
                    'question' => $request->question,
                    'start_date' => $request->start_date,
                    'end_date' => now()->addDays((int)($request->duration)),
                    'max_selections' => $request->max_selections,
                    'audience_can_add_options' => $request->audience_can_add_options,
                    'created_by' => Auth::id(),
                    'reveal_results' => $request->reveal_results,
                    'voters_are_visible' => $request->voters_are_visible,
                    'audience' => ($audience),

                ]);

                // Insert Poll Options (Bulk Insert)
                $options = collect($request->input('options'))->map(fn($option) => [
                    'poll_id' => $poll->id,
                    'option_text' => $option,
                    'created_by' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                PollOption::insert($options->toArray());

                return ApiService::success(new PollResource($poll));
            });
        } catch (\Throwable $e) {
            Log::error('Poll creation failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request' => $request->all(),
            ]);

            return ApiService::error(500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $userId = auth('sanctum')->user()?->id;
        $poll = Poll::with(['user', 'options'])
            ->withCount([
                'ups as ups_count',
                'downs as downs_count',
                'votes as total_votes' // Get total votes for percentage calculation
            ])
            ->when((bool)($userId), function ($query) use ($userId) {
                $query->withExists([
                    'votes as has_voted' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    },
                    'ups as has_upvoted' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    },
                    'downs as has_downvoted' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    }
                ]);

                // Load only the selected options if the user has voted
                $query->with([
                    'votes' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    }
                ]);
            })
            ->findOrFail($id);

        return ApiService::success(new PollResource($poll));
    }



    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Poll $poll)
    {
        //
    }

    public function status(Request $request, $pollId)
    {
        try {
            $poll = Poll::withTrashed()->findOrFail($pollId);
            if ($poll->trashed()) {
                // Restore the poll if it is already soft deleted
                $poll->restore();
                return ApiService::success([]);
            } else {
                // Soft delete the poll
                $poll->delete();
                return ApiService::success([]);
            }
        } catch (\Exception $e) {
            return ApiService::error(500, $e->getMessage());
        }
    }
    public function vote(StorePollVoteRequest $request)
    {
        // poll is not deleted
        $poll = Poll::findOrFail($request->poll_id);
        // poll did not start yet
        if ($poll->start_date->isFuture()) {
            return ApiService::error(400, 'poll_has_not_started_yet');
        }
        // poll is not expired
        if ($poll->end_date->isPast()) {
            return ApiService::error(400, 'poll_has_expired');
        }
        // user has not voted before
        if ($poll->votes()->where('user_id', Auth::id())->exists()) {
            return ApiService::error(400, 'you_have_already_voted');
        }
        // user is in the poll's audience
        $in_audience = UserService::canAnswerPoll($poll->id, request()->user());
        if (!$in_audience[0]) {
            return ApiService::error(400, 'user_is_not_in_poll_audience');
        }
        // user has not reached the max selections
        if (count($request->poll_option_id) > $poll->max_selections) {
            return ApiService::error(400, 'user_has_reached_the_max_selections');
        }
        // options are valid and belong to the poll
        $options = PollOption::whereIn('id', $request->poll_option_id)->where('poll_id', $poll->id)->get();
        if ($options->count() !== count($request->poll_option_id)) {
            return ApiService::error(400, 'invalid_options');
        }
        // save the vote
        $poll->votes()->createMany(
            collect($request->poll_option_id)->map(fn($optionId) => [
                'user_id' => request()->user()->id,
                'poll_option_id' => $optionId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray()
        );
        return ApiService::success([]);
    }
    public function react(StorePollReaction $request)
    {
        // poll is not deleted
        $poll = Poll::findOrFail($request->poll_id);
        // poll did not start yet
        if ($poll->start_date->isFuture()) {
            return ApiService::error(400, 'poll_has_not_started_yet');
        }
        // poll is not expired
        if ($poll->end_date->isPast()) {
            return ApiService::error(400, 'poll_has_expired');
        }
        // if any previous reaction exists, delete it
        $poll->reactions()->where('user_id', Auth::id())->delete();
        // save the reaction
        $poll->reactions()->create([
            'user_id' => Auth::id(),
            'reaction' => $request->reaction,
        ]);
        return ApiService::success([]);
    }
}
