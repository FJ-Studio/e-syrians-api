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
        return match (true) {
            in_array($event, ['verification_received', 'verification_revoked'], true) => self::Verifications,
            in_array($event, ['account_verified', 'account_unverified'], true) => self::AccountStatus,
            $event === 'poll_opened' => self::PollInvitations,
            $event === 'poll_closed' => self::PollResults,
            $event === 'announcement' => self::Announcements,
            str_starts_with($event, 'security_') => self::Security,
            str_starts_with($event, 'feature_') => self::FeatureRequests,
            default => throw new InvalidArgumentException('Unknown notification event.'),
        };
    }
}
