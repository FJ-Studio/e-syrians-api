<?php

declare(strict_types=1);

namespace App\Channels\Messages;

/**
 * Fluent builder for a OneSignal push payload.
 *
 * Used inside notification classes' `toOneSignal($notifiable)` method:
 *
 *   public function toOneSignal($notifiable): ?OneSignalMessage
 *   {
 *       return OneSignalMessage::create()
 *           ->subject(__('notifications.poll_open.title'))
 *           ->body(__('notifications.poll_open.body', ['name' => $poll->question]))
 *           ->data([
 *               'type' => 'poll-audience',
 *               'subject_id' => $poll->uuid,
 *           ]);
 *   }
 *
 * `data` is passed through as OneSignal's `data` attribute, which the
 * mobile SDK exposes as `additionalData` on the click event and which
 * `lib/notification-router.ts` reads to deep-link to the right screen.
 */
class OneSignalMessage
{
    public string $subject = '';

    public string $body = '';

    public ?string $url = null;

    public ?string $image = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function create(): self
    {
        return new self();
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /** Universal link / deep link opened when the notification is clicked. */
    public function url(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /** Big-image attachment shown in the expanded notification (rich push). */
    public function image(string $image): self
    {
        $this->image = $image;

        return $this;
    }

    /**
     * Additional data merged into the OneSignal `data` field — surfaces
     * to the mobile click handler as the `additionalData` object.
     *
     * @param  array<string, mixed>  $data
     */
    public function data(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Convert to the wire format OneSignal expects. Caller (the
     * service) merges `app_id` and the targeting fields on top.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // OneSignal supports a multi-language map per heading; for now
        // we send the same string under 'en' across the board. When we
        // start localising server-side per user, swap the single-string
        // setters for per-language arrays and update the map.
        $payload = [
            'headings' => ['en' => $this->subject],
            'contents' => ['en' => $this->body],
        ];

        if ($this->url !== null) {
            $payload['url'] = $this->url;
        }

        if ($this->image !== null) {
            $payload['big_picture'] = $this->image;          // Android
            $payload['ios_attachments'] = ['image' => $this->image]; // iOS
        }

        if (! empty($this->data)) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }
}
