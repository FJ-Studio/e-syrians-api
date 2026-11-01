<?php

declare(strict_types=1);

namespace App\Channels\Messages;

use App\Enums\NotificationCategory;

/** Push previews deliberately contain no names, IDs, poll questions or demographic data. */
final readonly class ExpoPushMessage
{
    /** @param array{type:string, subject_id?:string|int, recipient_uuid?:string} $data */
    public function __construct(
        public array $data = ['type' => 'notification'],
        public string $locale = 'en',
        public ?NotificationCategory $category = null
    ) {
    }

    public function notificationCategory(): NotificationCategory
    {
        return $this->category ?? match ($this->data['type']) {
            'verification' => NotificationCategory::Verifications,
            'poll-audience' => NotificationCategory::PollInvitations,
            'poll-results' => NotificationCategory::PollResults,
            'announcement' => NotificationCategory::Announcements,
            'security-event', 'security-2fa', 'security-signin' => NotificationCategory::Security,
            default => NotificationCategory::AccountStatus,
        };
    }

    /** @return array<string, mixed> */
    public function payload(string $token): array
    {
        return ['to' => $token, 'title' => 'E-Syrians',
            'body' => match ($this->locale) {
                'ar' => 'لديك إشعار جديد في E-Syrians.',
                'ku' => 'Li E-Syrians agahiyeke nû ji bo te heye.',
                default => 'You have a new update in E-Syrians.',
            }, 'sound' => 'default',
            'channelId' => 'e-syrians-updates', 'data' => $this->data];
    }
}
