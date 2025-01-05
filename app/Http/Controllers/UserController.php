<?php
declare(strict_types = 1);
namespace App\Http\Controllers;
use App\Helper\Helper;
use Illuminate\Support\Str;
use App\Http\Requests\UserRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use App\Http\Requests\UserLoginRequest;
use App\Http\Resources\paginateResource;
use App\Http\Requests\MarkAsFakeRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Requests\SocialLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\WeaponDelivery;
use App\Services\UserService;
use Illuminate\Http\Request;
class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $items = User::query();
        if ($request->filled('fake')) {
            $items->markedAsFake();
        }
        else {
            $items->verified();
        }
        $items = $items->orderBy('id' , $request->sort ? $request->sort : 'desc');
        $items = $items->paginate($request->perPage ? $request->perPage : 20);
        $data['data'] = UserResource::collection($items);
        if ($items instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $data['paginate'] = new paginateResource($items);
        }
        return Helper::apiResponse($data);

    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(UserRequest $request)
    {

        $data = $this->preperData($request);
        $item = User::query()->create($data);
        $data = UserResource::make($item);
        return Helper::apiResponse($data);

    }
    /**
     * Display the specified resource.
     */
    public function show(WeaponDelivery $weaponDelivery)
    {
        //
    }
    public function verifier(Request $request , $uuid)
    {
        if (is_null($request->user()->verified_at)) {
            return Helper::apiResponse('user_not_verified' , 400);
        }
        $user = User::where('uuid' , $uuid)->first();
        if (!$user) {
            return Helper::apiResponse('user_not_found' , 404);
        }
        if ($user->id == $request->user()->id) {
            return Helper::apiResponse('user_cannot_verified_himself' , 400);
        }
        // check if approved before
        $check = $user->verifiers()
            ->wherePivot('deleted_at', null)
            ->where('verified_by' , $request->user()->id)->first();
        if ($check) {
            return Helper::apiResponse('user_already_verified' , 400);
        }
        if ($user) {

            $user->verifiers()->attach($user , [
                'verified_by' => $request->user()->id ,
                'user_agent' => $request->header('User-Agent') ,
                'ip_address' => $request->ip() ,
            ]);
            // check if verified by 3 users
            if ($user->verifiers()->wherePivot('deleted_at', null)->count() >= 3) {
                $user->markAsVerified();
            }
        }
        return Helper::apiResponse('user_verified' , 200);
    }
    public function markAsFake(MarkAsFakeRequest $request , $uuid)
    {
        if (is_null($request->user()->verified_at)) {
            return Helper::apiResponse('user_not_verified' , 400);
        }
        $user = User::where('uuid' , $uuid)->first();
        if (!$user) {
            return Helper::apiResponse('user_not_found' , 404);
        }
        if ($user->id == $request->user()->id) {
            return Helper::apiResponse('user_cannot_verified_himself' , 400);
        }
        // check if approved before
        if ($user) {

            if ($user->marked_as_fake_at) {
                return Helper::apiResponse('user_already_marked_as_fake' , 400);
            }
            $user->markAsFake($request->reason);
        }
        return Helper::apiResponse('success' , 200);
    }
    /**
     * Show the form for editing the specified resource.
     */
    public function edit(WeaponDelivery $weaponDelivery)
    {
        //
    }
    public function update(UserUpdateRequest $request , $uuid)
    {
        $data = $this->preperData($request);
        // get value array of values that is not null
        $data = array_filter($data);
        $item = User::query()->where('uuid' , $uuid)->first();
        if (!$item) {
            return Helper::apiResponse('user_not_found' , 404);
        }
        $item->update($data);
        $data = UserResource::make($item);
        return Helper::apiResponse($data);
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
        return response()->json([
            'data' => new UserResource($request->user()) ,
        ] , 200 , [] , JSON_PRETTY_PRINT);
    }
    public function social_login(SocialLoginRequest $request)
    {
        $userData = UserService::getUserDataFromSocialProvider($request->provider , $request->token);
        if (!$userData) {
            return response()->json([
                'message' => 'invalid_user_token' ,
            ] , 401);
        }
        $provider_col = $request->provider . '_id';
        $user = User::where('email' , $userData['email'])->first();
        if (!$user) {
            $user = User::create([
                'email' => $userData['email'] ,
                'name' => $userData['name'] ,
                'social_avatar' => $userData['avatar'] ,
                $provider_col => $userData['id'] ,
            ]);
            $user->markEmailAsVerified();
            $user->assignRole('citizen');
        }
        return response()->json([
            'user' => new UserResource($user) ,
            'token' => explode('|' , $user->createToken($request->provider)->plainTextToken)[1] ,
        ]);
    }
    public function login(UserLoginRequest $request)
    {
        $hashedEmail = Helper::HashedValue($request->userId);
        $user = User::query()->where('email_hashed' , $hashedEmail)
            ->first();
        if (!$user) {
            return Helper::apiResponse('api.user_not_found' , 404);
        }
        if (Hash::check($request->password , $user->password)) {
            $token = $user->createToken('auth_token');
            return response()->json([
                'user' => new UserResource($user) ,
                'token' => $token->plainTextToken ,
            ]);
        }
        else {
            return Helper::apiResponse('invalid_credentials' , 401);
        }
    }
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json([
            'message' => 'logged_out' ,
        ]);
    }
    private function preperData($request): array
    {
        $data = $request->validated();
        if ($request->method() == 'PUT') {
            $briefName = $request->user()->brief_name;
            // If 'name' is provided, update the part before the comma
            if ($request->filled('name')) {
                $beforeComma = substr($request->input('name') , 0 , 3);
                $afterComma = Str::after($briefName , ',');
                $data['brief_name'] = $beforeComma . ',' . $afterComma;
            }
            // If 'last_name' is provided, update the part after the comma
            elseif ($request->filled('last_name')) {
                $beforeComma = Str::before($briefName , ',');
                $afterComma = substr($request->input('last_name') , 0 , 3);
                $data['brief_name'] = $beforeComma . ',' . $afterComma;
            }
        }
        else {
            $data['brief_name'] = substr($request->input('name' , '') , 0 , 2) . ',' . substr($request->input('last_name' , '') , 0 , 2);
        }
        $data['name_hashed'] = isset($data['name']) ? Helper::HashedValue($data['name']) : null;
        $data['middle_name_hashed'] = isset($data['middle_name']) ? Helper::HashedValue($data['middle_name']) : null;
        $data['last_name_hashed'] = isset($data['last_name']) ? Helper::HashedValue($data['last_name']) : null;
        $data['national_id_hashed'] = isset($data['national_id']) ? Helper::HashedValue($data['national_id']) : null;
        $data['phone_hashed'] = isset($data['phone']) ? Helper::HashedValue($data['phone']) : null;
        $data['email_hashed'] = isset($data['email']) ? Helper::HashedValue($data['email']) : null;
        $data['name'] = isset($data['name']) ? Crypt::encrypt($data['name']) : null;
        $data['middle_name'] = isset($data['middle_name']) ? Crypt::encrypt($data['middle_name']) : null;
        $data['last_name'] = isset($data['last_name']) ? Crypt::encrypt($data['last_name']) : null;
        $data['national_id'] = isset($data['national_id']) ? Crypt::encrypt($data['national_id']) : null;
        $data['address'] = isset($data['address']) ? $data['address'] : null;
        $data['estimated_monthly_income'] = isset($data['estimated_monthly_income']) ? $data['estimated_monthly_income'] : null;
        $data['phone'] = isset($data['phone']) ? Crypt::encrypt($data['phone']) : null;
        $data['email'] = isset($data['email']) ? Crypt::encrypt($data['email']) : null;
        return $data;
    }
}
