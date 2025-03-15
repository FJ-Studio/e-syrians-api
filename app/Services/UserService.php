<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

class UserService
{
    public static function getUserDataFromSocialProvider(string $provider, string $token): array|bool
    {
        $user = (Socialite::driver($provider))->userFromToken($token);
        if ($user) {
            return [
                $provider.'_id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
            ];
        }

        return false;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public static function canUserAVerifyUserB(int|string|User $userA, int|string|User $userB): array
    {
        if (is_int($userA)) {
            $userA = User::find($userA);
        } elseif (is_string($userA)) {
            $userA = User::where('uuid', $userA)->first();
        }
        if (! $userA instanceof User) {
            return [false, 'user_not_found'];
        }

        // 1. Check user A verfications limit
        $userACanVerify = $userA->canVerify();
        if (! $userACanVerify[0]) {
            return $userACanVerify;
        }
        // 2. find user B
        if (is_int($userB)) {
            $userB = User::find($userB);
        } elseif (is_string($userB)) {
            $userB = User::where('uuid', $userB)->first();
        }
        if (! $userB instanceof User) {
            return [false, 'target_user_not_found'];
        }

        // 3. check if user B is banned
        if ($userB->marked_as_fake_at) {
            return [false, 'user_is_banned'];
        }
        // 4. user cannot verify himself
        if ($userA->id === $userB->id) {
            return [false, 'you_cannot_verify_yourself'];
        }
        // 5. circular verification is not allowed, a user cannot verify another user who verified him
        if ($userA->verifiers()->where('verifier_id', $userB->id)->exists()) {
            return [false, 'circular_verification_not_allowed'];
        }
        // 6. user cannot verify the same user more than once
        if ($userA->verifications()->where('user_id', $userB->id)->whereNull('cancelled_at')->exists()) {
            return [false, 'you_have_already_verified_this_user'];
        }

        return [true, ''];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public static function canAnswerPoll(int $pollId, User $user): array
    {
        if (! $user) {
            return [false, 'user_not_found'];
        }
        $poll = Poll::find($pollId);
        if (! $poll) {
            return [false, 'poll_not_found'];
        }
        if ($user->hasAnsweredPoll($pollId)) {
            return [false, 'you_have_already_answered_this_poll'];
        }

        // check elligibility
        return $user->isInAudience($poll->audience);
    }
}
