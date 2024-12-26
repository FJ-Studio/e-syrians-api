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
        $user = $request->user();
        $data = $request->validated();
        // if the user is an admin, citizen_id will come from the request
        if ($user->hasRole('weapon_delivery_manager')) {
            if (isset($data['citizen_id'])) {
                $data['citizen_id'] = $data['citizen_id'];
            }
        } else {
            $data['citizen_id'] = $user->id;
        }

        // update user data
        $u = User::where('id', $data['citizen_id'])->first();
        $u->update([
            'national_id' => $data['national_id'],
            'national_id_hash' => $data['national_id'],
            'phone' => $data['phone'],
        ]);
        $u->setTranslation('name', 'ar', $data['name'])->save();
        $u->setTranslation('surname', 'ar', $data['surname'])->save();
        $u->setTranslation('name', 'en', $data['name'])->save();
        $u->setTranslation('surname', 'en', $data['surname'])->save();
        $u->setTranslation('name', 'ku', $data['name'])->save();
        $u->setTranslation('surname', 'ku', $data['surname'])->save();

        $data['deliveries'] = $data['weapons'];

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
