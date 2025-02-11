<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\User\CredentialsLoginRequest;
use App\Http\Requests\User\SocialLoginRequest;
use App\Http\Requests\User\UserStoreRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\WeaponDelivery;
use App\Services\ApiService;
use App\Services\StrService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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
    public function store(UserStoreRequest $request)
    {
        $data = $request->validated();
        if (isset($data['languages'])) {
            $data['languages'] = implode(',', $data['languages']);
        }
        if (isset($data['other_nationalities'])) {
            $data['other_nationalities'] = implode(',', $data['other_nationalities']);
        }
        $user = User::create($data);
        $user->assignRole('citizen');
        return ApiService::success(new UserResource($user), '', 201);
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
        return ApiService::success(new UserResource($request->user()));
    }

    public function social_login(SocialLoginRequest $request)
    {
        $userData = UserService::getUserDataFromSocialProvider($request->provider, $request->token);
        if (!$userData) {
            return ApiService::error(401);
        }

        $user = User::where('email', $userData['email'])->first();
        if (!$user) {
            return ApiService::error(401);
            // will no longer create an account for the user
            // $provider_col = $request->provider . '_id';
            // $user = User::create([
            //     'email' => $userData['email'],
            //     'name' => $userData['name'],
            //     'avatar' => $userData['avatar'],
            //     $provider_col => $userData['id'],
            // ]);
            // $user->markEmailAsVerified();
            // $user->assignRole('citizen');
        }
        return ApiService::success([
            'user' => new UserResource($user),
            'token' => explode('|', $user->createToken($request->provider)->plainTextToken)[1],
        ]);
    }

    public function login(CredentialsLoginRequest $request)
    {
        $identifier = StrService::hash($request->input('identifier'));
        $password = $request->input('password');
        $user = User::whereNotNull('email_hashed')->where('email_hashed', $identifier)
            ->orWhere(function ($query) use ($identifier) {
                $query->whereNotNull('phone_hashed')->where('phone_hashed', $identifier);
            })
            ->orWhere(function ($query) use ($identifier) {
                $query->whereNotNull('national_id_hashed')->where('national_id_hashed', $identifier);
            })
            ->first();

        if ($user && Hash::check($password, $user->password)) {
            return ApiService::success([
                'user' => new UserResource($user),
                'token' => explode('|', $user->createToken(date('YYYY-mm-dd-H:i:s'))->plainTextToken)[1],
            ]);
        }
        return ApiService::error(401);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json([
            'message' => 'logged_out',
        ]);
    }
}
