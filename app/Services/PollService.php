<?php

namespace App\Services;

use App\Enums\RevealResultsEnum;
use App\Models\Poll;
use App\Models\User;

class PollService
{
    public static function revealResults(Poll $poll, ?User $user): bool
    {
        if (!$poll || !$user) {
            return false;
        }
        if ($poll->reveal_results === RevealResultsEnum::BeforeVoting->value) {
            return true;
        }
        if ($poll->reveal_results === RevealResultsEnum::AfterExpiration->value) {
            return now()->isAfter($poll->end_date);
        }
        if ($poll->reveal_results === RevealResultsEnum::AfterVoting->value) {
            return $poll->votes()->where('user_id', $user->id)->exists();
        }
    }
}
