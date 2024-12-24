<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreWeaponDeliveryRequest;
use App\Http\Requests\UpdateWeaponDeliveryRequest;
use App\Models\WeaponDelivery;

class WeaponDeliveryController extends Controller
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
    public function store(StoreWeaponDeliveryRequest $request)
    {
        $weapon_delivery = WeaponDelivery::create($request->validated());
        if ($weapon_delivery) {
            return response()->json(['message' => 'Weapon delivery created successfully'], 201);
        } else {
            return response()->json(['message' => 'Failed to create weapon delivery'], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(WeaponDelivery $weaponDelivery)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(WeaponDelivery $weaponDelivery)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWeaponDeliveryRequest $request, WeaponDelivery $weaponDelivery)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WeaponDelivery $weaponDelivery)
    {
        //
    }
}
