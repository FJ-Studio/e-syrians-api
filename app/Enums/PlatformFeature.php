<?php

declare(strict_types=1);

namespace App\Enums;

enum PlatformFeature: string
{
    case NotificationEvents = 'notification-events';
    case ExpoPush = 'expo-push';
    case BigQueryExport = 'bigquery-export';
    case CryptoDonations = 'crypto-donations';

    public function label(): string
    {
        return match ($this) {
            self::NotificationEvents => 'Automatic notification generation',
            self::ExpoPush => 'Push notification delivery',
            self::BigQueryExport => 'BigQuery analytics export',
            self::CryptoDonations => 'Cryptocurrency donations',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NotificationEvents => 'Generate new automatic verification, account-status, poll, feature-request and security alerts. Existing queued alerts and manual announcements still follow delivery settings. Poll events paused here can catch up when re-enabled. Security Activity is always recorded.',
            self::ExpoPush => 'Allow queued push delivery through Expo. Enable only after APNs/FCM credentials and physical-device delivery tests are complete. Per-event flags, account preferences and device consent still apply.',
            self::BigQueryExport => 'Allow the existing audit pipeline to export profile changes, poll votes and audience rules to the configured BigQuery dataset. This can include personal data; server credentials and the BigQuery client package are required.',
            self::CryptoDonations => 'Show cryptocurrency donation options. Configure the public currencies, networks and receiving addresses on the Donations page. Card donations are unaffected.',
        };
    }

    public function defaultEnabled(): bool
    {
        return $this === self::NotificationEvents;
    }
}
