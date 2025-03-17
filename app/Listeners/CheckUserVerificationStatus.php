<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\VerificationReceived;
use App\Mail\UserAccountVerified;
use App\Mail\UserReceivedVerification;
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
        if (! $event->recipient->isVerified()) {
            $threshold = config('e-syrians.verification.min', 3);
            if ($event->recipient->activeVerifiers()->count() === $threshold) {
                $event->recipient->verified_at = now();
                $event->recipient->save();
                if ($event->recipient->account_verified_email) {
                    // send email notification to tell the user he has been verified
                    Mail::to($event->recipient)->send(new UserAccountVerified($event->recipient));
                }

                return;
            }
        }
        if ($event->recipient->received_verification_email) {
            // send email notification telling that the user data has been verified by another user.
            Mail::to($event->recipient)->send(new UserReceivedVerification($event->sender, $event->recipient));
        }
    }
}
