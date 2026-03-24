<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\VerificationServiceContract;
use App\Models\Poll;
use App\Models\User;

/**
 * @deprecated Use AuthService, VerificationService, or PollService instead.
 * This class is retained temporarily for backward compatibility.
 */
class UserService
{
    /**
     * @deprecated Use VerificationService::canUserVerify() instead.
     *
     * @return array{0: bool, 1: string}
     */
    public static function canUserAVerifyUserB(int|string|User $userA, int|string|User $userB): array
    {
        return app(VerificationServiceContract::class)->canUserVerify($userA, $userB);
    }

    /**
     * @deprecated Use PollService::vote() which includes audience validation.
     *
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

        return $user->isInAudience($poll->audience);
    }
}
