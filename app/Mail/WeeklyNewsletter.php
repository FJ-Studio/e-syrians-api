<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;

class WeeklyNewsletter extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $userLocale;

    /**
     * @param  Collection  $polls           Polls created this week (with options eager-loaded)
     * @param  Collection  $featureRequests Feature requests created this week
     * @param  string      $userLocale      Recipient locale (en, ar, ku)
     */
    public function __construct(
        public Collection $polls,
        public Collection $featureRequests,
        string $userLocale = 'ar',
    ) {
        $this->userLocale = $userLocale;
        $this->locale($this->userLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.newsletter_subject'),
        );
    }

    public function content(): Content
    {
        $frontendUrl = config('app.frontend_url');

        return new Content(
            view: 'mail.weekly-newsletter',
            with: [
                'polls' => $this->polls,
                'featureRequests' => $this->featureRequests,
                'frontendUrl' => $frontendUrl,
                'userLocale' => $this->userLocale,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
