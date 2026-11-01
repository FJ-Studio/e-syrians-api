<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationEvent: string
{
    case VerificationReceived = 'verification_received';
    case VerificationRevoked = 'verification_revoked';
    case AccountVerified = 'account_verified';
    case AccountUnverified = 'account_unverified';
    case PollOpened = 'poll_opened';
    case PollClosed = 'poll_closed';
    case FeatureIdea = 'feature_idea';
    case FeatureInDevelopment = 'feature_in_development';
    case FeatureInTesting = 'feature_in_testing';
    case FeatureShipped = 'feature_shipped';
    case Announcement = 'announcement';
    case SecurityNewSignin = 'security_new_signin';
    case SecurityPasswordChanged = 'security_password_changed';
    case SecurityPasswordSet = 'security_password_set';
    case SecurityTwoFactorEnabled = 'security_two_factor_enabled';
    case SecurityTwoFactorDisabled = 'security_two_factor_disabled';

    public function label(): string
    {
        return match ($this) {
            self::VerificationReceived => 'Verification received',
            self::VerificationRevoked => 'Verification revoked',
            self::AccountVerified => 'Account becomes verified',
            self::AccountUnverified => 'Account loses verified status',
            self::PollOpened => 'Eligible poll opens',
            self::PollClosed => 'Poll closes / results available',
            self::FeatureIdea => 'Feature request returns to idea',
            self::FeatureInDevelopment => 'Feature request enters development',
            self::FeatureInTesting => 'Feature request enters testing',
            self::FeatureShipped => 'Feature request ships',
            self::Announcement => 'Platform announcement',
            self::SecurityNewSignin => 'New-device / unidentified-client sign-in',
            self::SecurityPasswordChanged => 'Password changed or reset',
            self::SecurityPasswordSet => 'First password added',
            self::SecurityTwoFactorEnabled => 'Two-factor authentication enabled',
            self::SecurityTwoFactorDisabled => 'Two-factor authentication disabled',
        };
    }

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::VerificationReceived, self::VerificationRevoked => NotificationCategory::Verifications,
            self::AccountVerified, self::AccountUnverified => NotificationCategory::AccountStatus,
            self::PollOpened => NotificationCategory::PollInvitations,
            self::PollClosed => NotificationCategory::PollResults,
            self::FeatureIdea, self::FeatureInDevelopment, self::FeatureInTesting, self::FeatureShipped => NotificationCategory::FeatureRequests,
            self::Announcement => NotificationCategory::Announcements,
            self::SecurityNewSignin, self::SecurityPasswordChanged, self::SecurityPasswordSet,
            self::SecurityTwoFactorEnabled, self::SecurityTwoFactorDisabled => NotificationCategory::Security,
        };
    }
}
