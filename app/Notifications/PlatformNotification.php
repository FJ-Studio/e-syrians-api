<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Channels\ExpoPushChannel;
use App\Enums\NotificationCategory;
use App\Channels\Messages\ExpoPushMessage;
use Illuminate\Notifications\Notification;

class PlatformNotification extends Notification
{
    public function __construct(
        public string $event,
        public string $subjectId,
        public ?string $announcementTitle = null,
        public ?string $announcementBody = null
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ExpoPushChannel::class];
    }

    public function routingType(): string
    {
        return match ($this->event) {
            'verification_received', 'verification_revoked', 'account_verified', 'account_unverified' => 'verification',
            'poll_opened' => 'poll-audience',
            'poll_closed' => 'poll-results',
            'security_two_factor_enabled', 'security_two_factor_disabled' => 'security-2fa',
            'security_new_signin' => 'security-signin',
            'security_password_changed', 'security_password_set' => 'security-event',
            'announcement' => 'announcement',
            default => 'feature-request-status',
        };
    }

    /** @return array<string, string|null> */
    public function toDatabase(object $notifiable): array
    {
        return ['type' => $this->routingType(), 'subject_id' => $this->subjectId,
            'title_key' => 'notificationEvents.'.$this->event.'.title',
            'body_key' => 'notificationEvents.'.$this->event.'.body',
            'title' => $this->announcementTitle ?? 'E-Syrians',
            'body' => $this->announcementBody ?? 'You have a new update in E-Syrians.'];
    }

    public function toExpoPush(User $user): ExpoPushMessage
    {
        return new ExpoPushMessage(['type' => $this->routingType(), 'subject_id' => $this->subjectId,
            'recipient_uuid' => $user->uuid], category: NotificationCategory::forEvent($this->event));
    }
}
