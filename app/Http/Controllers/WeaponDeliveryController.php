<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreWeaponDeliveryRequest;
use App\Http\Requests\UpdateWeaponDeliveryRequest;
use App\Models\User;
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
        $user_id = $request->user()->id;
        $data = $request->validated();
        // if the user is not an admin, set the citizen_id to the current user's id
        if (isset($data['citizen_id'])) {
            $data['citizen_id'] = $data['citizen_id'];
            // update user data
            User::where('id', $user_id)->update([
                'national_id' => $data['national_id'],
                'national_id_hash' => $data['national_id'],
                'name' => $data['name'],
                'surname' => $data['surname'],
                'phone' => $data['phone'],
            ]);
        } else {
            $data['citizen_id'] = $user_id;
        }

        $data['deliveries'] = explode(',', $data['weapons']);

        $weapon_delivery = WeaponDelivery::create($data);
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
