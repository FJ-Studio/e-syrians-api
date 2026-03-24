<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    /**
     * Get the current authenticated user
     */
    public function me(Request $request): JsonResponse
    {
        return ApiService::success(new UserResource($request->user()));
    }

    /**
     * Display a specific user by UUID (public profile)
     */
    public function show(User $user): JsonResponse
    {
        return ApiService::success(new UserResource($user));
    }

    /**
     * Get the first verified registrants with social links
     */
    public function first(): JsonResponse
    {
        $socials = [
            'facebook_link', 'github_link', 'twitter_link', 'linkedin_link',
            'instagram_link', 'youtube_link', 'tiktok_link', 'pinterest_link',
            'twitch_link', 'snapchat_link', 'website',
        ];

        $users = Cache::remember('verified_first_registrants', now()->addHours(3), function () use ($socials) {
            return User::query()
                ->whereNotNull('verified_at')
                ->where('verification_reason', 'first_registrant')
                ->whereNotNull('avatar')
                ->where(function ($query) use ($socials) {
                    $first = array_shift($socials);
                    $query->whereNotNull($first);
                    foreach ($socials as $column) {
                        $query->orWhereNotNull($column);
                    }
                })
                ->get();
        });

        return ApiService::success(UserResource::collection($users));
    }
}
