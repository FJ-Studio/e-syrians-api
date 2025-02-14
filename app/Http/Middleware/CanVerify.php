<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ApiService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanVerify
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // 1. user should not be banned
        if ($user->marked_as_fake_at) {
            return ApiService::error(403, 'your_account_is_banned');
        }
        // 2. user should be verified
        if (!$user->verified_at) {
            return ApiService::error(403, 'you_are_not_verified');
        }

        // first registrant can verify without restrictions
        // TODO: include admins in this exception
        if ($user->verification_reason !== 'first_registrant') {
            // 3. user cannot verify more than the count of verifications he got
            $receivedVerifications = $user->verifiers()->count();
            $givenVerifications = $user->verifications()->count();
            $threshold = config('e-syrians.verification'); // array
            // A. If you do not have enough verifications
            if ($receivedVerifications < $threshold['min']) {
                return ApiService::error(403, 'you_do_not_have_enough_verifications');
            }
            // B. If you have reached the maximum verifications allowed to make
            if ($givenVerifications >= $threshold['max']) {
                return ApiService::error(403, 'you_have_reached_the_maximum_verifications');
            }
            // C. If the difference between verifiers and number of verifications is less than the threshold
            if ($receivedVerifications - $givenVerifications < $threshold['diff']) {
                return ApiService::error(403, 'you_do_not_have_enough_verifications');
            }
        }

        $targetUuid = $request->input('uuid');
        // 4. user cannot verify himself
        if ($user->uuid === $targetUuid) {
            return ApiService::error(403, 'you_cannot_verify_yourself');
        }
        // 5. circular verification is not allowed, a user cannot verify another user who verified him
        $targetUser = User::where('uuid', $targetUuid)->firstOrFail();
        if ($user->verifiers()->where('verifier_id', $targetUser->id)->exists()) {
            return ApiService::error(403, 'circular_verification_not_allowed');
        }
        // 6. user cannot verify the same user more than once
        if ($user->verifications()->where('user_id', $targetUser->id)->whereNull('cancelled_at')->exists()) {
            return ApiService::error(403, 'you_have_already_verified_this_user');
        }
        return $next($request);
    }
}
