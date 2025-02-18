<?php

namespace App\Http\Controllers;

use App\Http\Requests\Polls\StorePollRequest;
use App\Http\Resources\PollResource;
use App\Models\Poll;
use App\Models\PollOption;
use App\Services\ApiService;
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
        $polls = Poll::whereYear('start_date', 2025)
            ->whereMonth('start_date', 2)
            ->with('user')
            ->with('options')
            ->withCount([
                'ups as ups_count',
                'downs as downs_count'
            ])
            ->orderByRaw('(ups_count - downs_count) DESC')
            ->get();
        return (PollResource::collection($polls));
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
    public function show(Poll $poll)
    {
        $poll->load([
            'user',
            'options',
        ])->loadCount([
            'ups as ups_count',
            'downs as downs_count',
        ]);

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
}
