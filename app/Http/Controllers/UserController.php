<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SocialLoginRequest;
use App\Http\Resources\v1\UserResource;
use App\Models\User;
use App\Models\WeaponDelivery;
use App\Services\UserService;
use Illuminate\Http\Request;

class UserController extends Controller
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
    public function create() {}

    /**
     * Store a newly created resource in storage.
     */
    // public function store($request)
    // {

    // }

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
    // public function update($request, WeaponDelivery $weaponDelivery)
    // {

    // }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WeaponDelivery $weaponDelivery)
    {
        //
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    public function social_login(SocialLoginRequest $request)
    {
        $userData = UserService::getUserDataFromSocialProvider($request->provider, $request->token);
        if (!$userData) {
            return response()->json([
                'message' => 'invalid_user_token',
            ], 401);
        }
        $provider_col = $request->provider . '_id';

        $user = User::where('email', $userData['email'])->first();
        if (!$user) {
            $user = User::create([
                'email' => $userData['email'],
                'name' => $userData['name'],
                'social_avatar' => $userData['avatar'],
                'email_verified_at' => date('Y-m-d H:i:s'),
                $provider_col => $userData['id'],
            ]);
            $user->assignRole('citizen');
        }
        return response()->json([
            'user' => $user,
            'token' => explode('|', $user->createToken($request->provider)->plainTextToken)[1],
        ]);
    }

    public function login() {}

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json([
            'message' => 'logged_out',
        ]);
    }
}
