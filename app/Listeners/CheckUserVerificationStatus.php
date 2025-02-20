<?php

namespace App\Listeners;

use App\Events\VerificationReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class CheckUserVerificationStatus
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(VerificationReceived $event): void
    {
        $to = $event->to;

        if (!$to->isVerified()) {
            $threshold = config('e-syrians.verification.min', 3);
            if ($to->activeVerifiers()->count() === $threshold) {
                $to->verified_at = now();
                $to->save();
                // send email notification to tell the user he has been verified
            }
        }
    }
}
