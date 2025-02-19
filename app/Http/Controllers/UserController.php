<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProfileChangeTypeEnum;
use App\Http\Requests\User\CredentialsLoginRequest;
use App\Http\Requests\User\SocialLoginRequest;
use App\Http\Requests\User\UpdateSocialLinksRequest;
use App\Http\Requests\User\UpdateUserAddressRequest;
use App\Http\Requests\User\UpdateUserAvatarRequest;
use App\Http\Requests\User\UpdateUserBasicInfoRequest;
use App\Http\Requests\User\UpdateUserCensusDataRequest;
use App\Http\Requests\User\UserStoreRequest;
use App\Http\Requests\User\VerifyUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\UserVerificationResource;
use App\Models\User;
use App\Models\WeaponDelivery;
use App\Services\ApiService;
use App\Services\StrService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
     * Get the first registrants people
     */
    public function first()
    {
        $users = Cache::remember('verified_first_registrants', now()->addHours(3), function () {
            return User::whereNotNull('verified_at')
                ->where('verification_reason', 'first_registrant')
                ->get();
        });

        return ApiService::success(UserResource::collection($users));
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
    public function show(User $user)
    {
        return ApiService::success(new UserResource($user));
    }

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
        if (! $userData) {
            return ApiService::error(401);
        }

        $user = User::where('email', $userData['email'])->first();
        if (! $user) {
            return ApiService::error(401);
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

    public function update_basic_info(UpdateUserBasicInfoRequest $request)
    {
        try {
            $user = $request->user();
            if ($user->getTotalUpdatesCount(ProfileChangeTypeEnum::BasicData->value) >= config('e-syrians.verification.basic_data_updates_limit')) {
                return ApiService::error(403, 'basic_info_updates_limit_reached');
            }
            $data = $request->validated();
            $user->update($data);
            $user->profileUpdates()->create([
                'change_type' => ProfileChangeTypeEnum::BasicData->value,
                'meta_data' => [],
            ]);
            // update the user verifications after updating user basic info
            $user->verifications()
                ->whereNull('cancelled_at')
                ->update([
                    'cancelled_at' => now(),
                    'cancelation_payload' => [
                        'reason' => 'user_updated_basic_info',
                    ],
                ]);

            $user->markAsUnverified();

            return ApiService::success([]);
        } catch (\Exception $e) {
            return ApiService::error(500, $e->getMessage());
        }
    }

    public function update_social_links(UpdateSocialLinksRequest $request)
    {
        try {
            $user = $request->user();
            $data = $request->validated();
            $user->update($data);

            return ApiService::success([]);
        } catch (\Exception $e) {
            return ApiService::error(500, $e->getMessage());
        }
    }

    public function update_avatar(UpdateUserAvatarRequest $request)
    {
        try {
            $user = $request->user();

            $file = $request->file('avatar');
            $ext = $file->getClientOriginalExtension();
            $fileName = $user->uuid . '.' . $ext;

            // Delete old avatar if it exists
            if (! empty($user->avatar) && Storage::disk('s3')->exists($user->avatar)) {
                Storage::disk('s3')->delete($user->avatar);
            }

            // Upload new avatar
            $path = $file->storeAs('avatars', $fileName, 's3');

            // Update user avatar path
            $user->avatar = $path;
            $user->save();

            // Generate the full URL
            $url = Storage::disk('s3')->url($path);

            return ApiService::success([
                'url' => $url,
            ]);
        } catch (\Exception $e) {
            return ApiService::error(500, $e->getMessage());
        }
    }

    public function update_address(UpdateUserAddressRequest $request)
    {
        try {
            $user = $request->user();
            if ($user->getAddressUpdatesCount(ProfileChangeTypeEnum::Address->value) >= config('e-syrians.verification.country_updates_limit')) {
                return ApiService::error(403, 'country_updates_limit_reached');
            }
            $user = $request->user();
            $data = $request->validated();
            $user->update($data);

            return ApiService::success([]);
        } catch (\Exception $e) {
            return ApiService::error(500, $e->getMessage());
        }
    }

    public function update_census(UpdateUserCensusDataRequest $request)
    {
        try {
            $user = $request->user();
            $data = $request->validated();
            $data = $request->validated();
            $data['languages'] = implode(',', $data['languages'] ?? []);
            $data['other_nationalities'] = implode(',', $data['other_nationalities'] ?? []);
            $user->update($data);

            return ApiService::success([]);
        } catch (\Exception $e) {
            return ApiService::error(500, $e->getMessage());
        }
    }

    public function verify(VerifyUserRequest $request)
    {
        try {
            $user = $request->user();
            $targetUuid = $request->input('uuid');
            $targetUser = User::where('uuid', $targetUuid)->firstOrFail();
            $targetUser->verifiers()->create([
                'verifier_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return ApiService::success([]);
        } catch (\Exception $e) {
            return ApiService::error(500, $e->getMessage());
        }
    }

    public function my_polls(Request $request)
    {
        $user = $request->user();
        $polls = $user->polls()
            ->withTrashed()
            ->withCount('votes')
            ->paginate(25);

        return ApiService::success([
            'polls' => $polls->items(),
            'total' => $polls->total(),
            'per_page' => $polls->perPage(),
            'current_page' => $polls->currentPage(),
            'last_page' => $polls->lastPage(),
        ]);
    }

    public function my_verifications(Request $request)
    {
        $user = $request->user();

        return ApiService::success($user->verifications()
            ->with('user')
            ->get());
    }

    public function my_verifiers(Request $request)
    {
        $user = $request->user();

        return ApiService::success(
            UserVerificationResource::collection(
                $user->verifiers()->with('verifier')->get()
            )
        );
    }

    public function my_reactions(Request $request)
    {
        $reactions = $request->user()->reactions()
            ->with('poll')
            ->paginate(25);

        return ApiService::success(
            [
                $reactions->items(),
                'reactions' => $reactions->items(),
                'total' => $reactions->total(),
                'per_page' => $reactions->perPage(),
                'current_page' => $reactions->currentPage(),
                'last_page' => $reactions->lastPage(),
            ]
        );
    }



    public function my_votes(Request $request)
    {
        $perPage = $request->query('per_page', 25);
        $page = $request->query('page', 1);
        $userVotes = $request->user()->votes()
            ->with('pollOption.poll')
            ->get()
            ->groupBy('pollOption.poll_id')
            ->map(function ($votes) {
                $poll = $votes->first()->pollOption->poll;
                return [
                    'poll_id' => $poll->id,
                    'question' => $poll->question,
                    'selected_options' => $votes->pluck('pollOption.option_text'),
                    'voted_at' => $votes->first()->created_at,
                ];
            })->values();

        // Paginate manually
        $paginatedVotes = new LengthAwarePaginator(
            $userVotes->forPage($page, $perPage), // Slice the collection for pagination
            $userVotes->count(), // Total count
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return ApiService::success($paginatedVotes);
    }
}
