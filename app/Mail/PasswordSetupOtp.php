<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;

class PasswordSetupOtp extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {
        $this->locale($user->language ?? config('app.locale'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.otp_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-setup-otp',
            with: [
                'user' => $this->user,
                'code' => $this->code,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
