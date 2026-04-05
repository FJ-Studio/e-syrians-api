<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Violations\StoreViolationRequest;
use App\Http\Requests\Violations\UpdateViolationAttachmentRequest;
use App\Http\Requests\Violations\UpdateViolationRequest;
use App\Http\Resources\ViolationResource;
use App\Models\Violation;
use App\Services\ApiService;

class ViolationController extends Controller
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
    public function store(StoreViolationRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();
        $violation = $user->violations()->create($validated);
        if (! $violation) {
            throw new \Exception('Failed to create violation');
        }

        return ApiService::success(new ViolationResource($violation), [], 201);
    }

    /**
     * Update violations attachments
     */
    public function attachments(UpdateViolationAttachmentRequest $request, Violation $violation)
    {
        $validated = $request->validated();
        $violation->attachments = $validated['attachments'];
        $violation->save();

        return ApiService::success(new ViolationResource($violation));
    }

    /**
     * React to a violation
     */
    public function react(\Illuminate\Http\Request $request)
    {
        // TODO: Implement violation reactions
        return ApiService::error(501, 'not_implemented');
    }

    /**
     * Display the specified resource.
     */
    public function show(Violation $violation)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateViolationRequest $request, Violation $violation)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Violation $violation)
    {
        //
    }
}
