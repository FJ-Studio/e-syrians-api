<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

enum NotificationCategory: string
{
    case Verifications = 'verifications';
    case AccountStatus = 'account_status';
    case PollInvitations = 'poll_invitations';
    case PollResults = 'poll_results';
    case FeatureRequests = 'feature_requests';
    case Announcements = 'announcements';
    case Security = 'security';

    public static function forEvent(string $event): self
    {
        return NotificationEvent::tryFrom($event)?->category()
            ?? throw new InvalidArgumentException('Unknown notification event.');
    }

    public function label(): string
    {
        return match ($this) {
            self::Verifications => 'Verifications',
            self::AccountStatus => 'Account verification status',
            self::PollInvitations => 'Poll invitations',
            self::PollResults => 'Poll closing / results',
            self::FeatureRequests => 'Feature-request updates',
            self::Announcements => 'Platform announcements',
            self::Security => 'Security alerts',
        };
    }
}
