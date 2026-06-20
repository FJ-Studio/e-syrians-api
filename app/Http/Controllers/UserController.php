<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\UserResource;
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
     * Get the first verified registrants with social links.
     *
     * We cache only the matching user UUIDs (plain strings), NOT the full
     * Eloquent Collection. Two reasons:
     *
     *   1. Caching an `Eloquent\Collection` via the file driver serializes
     *      the model graph and, on retrieval, PHP can hand back a
     *      `__PHP_Incomplete_Class` (autoloader-ordering quirk that bit
     *      this endpoint with a `CollectsResources.php:42` fatal). Caching
     *      primitives sidesteps the whole problem.
     *   2. `UserResource::toArray` mints a fresh signed avatar URL each
     *      time it runs (TTL ~60 min from `e-syrians.files.avatar.ttl`).
     *      Caching the materialized response for 3 hours would serve
     *      stale URLs after the first hour. Re-fetching on every request
     *      keeps avatars fresh; what we're actually saving by caching is
     *      the expensive `WHERE … OR …` social-link scan, which is what
     *      the UUID list captures.
     */
    public function first(): JsonResponse
    {
        $uuids = Cache::remember('verified_first_registrant_uuids', now()->addHours(3), function () {
            $socials = [
                'facebook_link', 'github_link', 'twitter_link', 'linkedin_link',
                'instagram_link', 'youtube_link', 'tiktok_link', 'pinterest_link',
                'twitch_link', 'snapchat_link', 'website',
            ];

            return User::query()
                ->whereNotNull('verified_at')
                ->where('verification_reason', 'first_registrant')
                ->whereNotNull('avatar')
                ->where(function ($query) use ($socials): void {
                    $first = array_shift($socials);
                    $query->whereNotNull($first);
                    foreach ($socials as $column) {
                        $query->orWhereNotNull($column);
                    }
                })
                // Stable order so the cached list and the re-fetch agree —
                // and "first" actually means earliest-verified.
                ->oldest('verified_at')
                ->pluck('uuid')
                ->all();
        });

        if (empty($uuids)) {
            return ApiService::success([]);
        }

        $users = User::whereIn('uuid', $uuids)
            ->orderBy('verified_at')
            ->get();

        return ApiService::success(UserResource::collection($users));
    }
}
