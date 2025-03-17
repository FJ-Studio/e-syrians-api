<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Polls\StorePollReactionRequest;
use App\Http\Requests\Polls\UpdatePollReactionRequest;
use App\Models\PollReaction;

class PollReactionController extends Controller
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
    public function store(StorePollReactionRequest $request) {}

    /**
     * Display the specified resource.
     */
    public function show(PollReaction $pollReaction)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PollReaction $pollReaction) {}

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePollReactionRequest $request, PollReaction $pollReaction)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PollReaction $pollReaction)
    {
        //
    }
}
