<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSecurityPersonnelRequest;
use App\Http\Requests\UpdateSecurityPersonnelRequest;
use App\Models\SecurityPersonnel;

class SecurityPersonnelController extends Controller
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
    public function store(StoreSecurityPersonnelRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(SecurityPersonnel $securityPersonnel)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SecurityPersonnel $securityPersonnel)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSecurityPersonnelRequest $request, SecurityPersonnel $securityPersonnel)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SecurityPersonnel $securityPersonnel)
    {
        //
    }
}
