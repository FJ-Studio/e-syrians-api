<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Polls\StorePollVoteRequest;
use App\Http\Requests\Polls\UpdatePollVoteRequest;
use App\Models\PollVote;

class PollVoteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePollVoteRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(PollVote $pollVote)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PollVote $pollVote)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePollVoteRequest $request, PollVote $pollVote)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PollVote $pollVote)
    {
        //
    }
}
