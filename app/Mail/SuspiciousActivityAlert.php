<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Models\SuspiciousActivity;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;

class SuspiciousActivityAlert extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SuspiciousActivity $activity,
        public ?User $user,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[e-syrians] Suspicious activity — User #%d (severity: %s)',
                $this->activity->user_id,
                $this->activity->severity,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.suspicious-activity-alert',
            with: [
                'activity' => $this->activity,
                'user' => $this->user,
                'frontendUrl' => config('app.frontend_url'),
            ],
        );
    }
}
