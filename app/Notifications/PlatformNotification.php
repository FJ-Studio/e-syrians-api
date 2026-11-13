<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Channels\ExpoPushChannel;
use App\Enums\NotificationCategory;
use App\Channels\Messages\ExpoPushMessage;
use Illuminate\Notifications\Notification;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationDeliverySettingsService;

class PlatformNotification extends Notification
{
    public function __construct(
        public string $event,
        public ?string $subjectId,
        public ?string $announcementTitle = null,
        public ?string $announcementBody = null
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }
        $category = NotificationCategory::forEvent($this->event);
        $settings = resolve(NotificationDeliverySettingsService::class);
        $preferences = resolve(NotificationPreferenceService::class);
        $channels = [];
        if ($settings->allows($this->event, 'in_app') && $preferences->allows($notifiable, $category, 'in_app')) {
            $channels[] = 'database';
        }
        if ($settings->allows($this->event, 'push') && $preferences->allows($notifiable, $category, 'push')) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    public function routingType(): string
    {
        return match ($this->event) {
            'verification_received', 'verification_revoked', 'account_verified', 'account_unverified' => 'verification',
            'identity_credit_granted', 'identity_credit_gifted' => 'identity-verification',
            'poll_opened' => 'poll-audience',
            'poll_closed' => 'poll-results',
            'security_two_factor_enabled', 'security_two_factor_disabled' => 'security-2fa',
            'security_new_signin' => 'security-signin',
            'security_password_changed', 'security_password_set' => 'security-event',
            'announcement' => 'announcement',
            default => 'feature-request-status',
        };
    }

    /** @return array<string, string> */
    public function toDatabase(object $notifiable): array
    {
        $title = match ($this->event) {
            'identity_credit_granted' => 'An admin added an ID verification credit',
            'identity_credit_gifted' => 'You received an ID verification credit as a gift',
            default => $this->announcementTitle ?? 'E-Syrians',
        };
        $body = $this->routingType() === 'identity-verification'
            ? 'A credit is ready in Account → ID verification.'
            : $this->announcementBody ?? 'You have a new update in E-Syrians.';

        return ['type' => $this->routingType(),
            ...($this->subjectId !== null ? ['subject_id' => $this->subjectId] : []),
            'title_key' => 'notificationEvents.'.$this->event.'.title',
            'body_key' => 'notificationEvents.'.$this->event.'.body',
            'title' => $title, 'body' => $body];
    }

    public function toExpoPush(User $user): ExpoPushMessage
    {
        return new ExpoPushMessage(['type' => $this->routingType(),
            ...($this->subjectId !== null ? ['subject_id' => $this->subjectId] : []),
            'recipient_uuid' => $user->uuid], category: NotificationCategory::forEvent($this->event), event: $this->event);
    }
}
