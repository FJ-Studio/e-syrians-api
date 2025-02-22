<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserVerificationRequest;
use App\Http\Requests\UpdateUserVerificationRequest;
use App\Models\UserVerification;

class UserVerificationController extends Controller
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
    public function store(StoreUserVerificationRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(UserVerification $userVerification)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(UserVerification $userVerification)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserVerificationRequest $request, UserVerification $userVerification)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UserVerification $userVerification)
    {
        //
    }
}
