<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;

class EmailChangedNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $newEmail,
    ) {
        $this->locale($user->language ?? config('app.locale'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.email_changed_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-changed',
            with: [
                'user' => $this->user,
                'newEmail' => $this->newEmail,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
