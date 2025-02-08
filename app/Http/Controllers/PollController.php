<?php

namespace App\Http\Controllers;

use App\Http\Requests\Polls\StorePollRequest;
use App\Http\Resources\PollResource;
use App\Models\Poll;
use App\Models\PollOption;
use App\Services\ApiService;
use Illuminate\Support\Facades\Auth;

class PollController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePollRequest $request)
    {
        $audience = [
            'gender' => $request->input('gender', []),
            'age_range' => [
                'min' => $request->input('min_age'),
                'max' => $request->input('max_age'),
            ],
            'country' => $request->input('country', []), // Now an array
            'religious_affiliation' => $request->input('religious_affiliation', []),
            'hometown' => $request->input('hometown', []),
            'ethnicity' => $request->input('ethnicity', []),
        ];
        // Create the poll
        $poll = Poll::create([
            'question' => $request->question,
            'start_date' => $request->start_date,
            'end_date' => now()->addDays($request->duration),
            'max_selections' => $request->max_selections,
            'audience_can_add_options' => $request->audience_can_add_options,
            'audience' => json_encode($audience), // Store as JSON
            'created_by' => Auth::id(),
        ]);
        // Step 2: Insert Poll Options
        $options = collect($request->input('options'))->map(function ($option) use ($poll) {
            return [
                'poll_id' => $poll->id,
                'option_text' => $option,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        });

        PollOption::insert($options->toArray());

        return ApiService::success(
            new PollResource($poll),
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Poll $poll)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Poll $poll)
    {
        //
    }
}
