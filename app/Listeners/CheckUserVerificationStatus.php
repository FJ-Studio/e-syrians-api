<?php

namespace App\Listeners;

use App\Events\VerificationReceived;
use App\Mail\UserReceivedVerification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

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
        $event->to;

        if (!$event->to->isVerified()) {
            $threshold = config('e-syrians.verification.min', 3);
            if ($event->to->activeVerifiers()->count() === $threshold) {
                $event->to->verified_at = now();
                $event->to->save();
                if ($event->to->account_verified_email) {
                    // send email notification to tell the user he has been verified
                }
                return;
            }
        }
        if ($event->to->received_verification_email) {
            // send email notification telling that the user data has been verified by another user.
            Mail::to($event->to->email)->send(new UserReceivedVerification($event->from, $event->to));
        }
    }
}
