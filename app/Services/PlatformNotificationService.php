<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Ramsey\Uuid\Uuid;
use App\Channels\ExpoPushChannel;
use Illuminate\Support\Facades\DB;
use App\Enums\NotificationCategory;
use App\Notifications\PlatformNotification;
use Illuminate\Database\UniqueConstraintViolationException;

class PlatformNotificationService
{
    public function send(User $user, PlatformNotification $notification, string $source): void
    {
        $notification->id = self::deliveryId($user, $notification->event, $source);
        try {
            DB::transaction(function () use ($user, $notification): void {
                $recipient = User::query()->whereKey($user->id)->lockForUpdate()->first();
                if (! $recipient || $recipient->deletion_scheduled_for || $recipient->marked_as_fake_at) {
                    return;
                }
                if (! DB::table('notification_deliveries')->where('id', $notification->id)->exists()) {
                    $category = NotificationCategory::forEvent($notification->event);
                    $preferences = resolve(NotificationPreferenceService::class);
                    $inApp = $preferences->allows($recipient, $category, 'in_app');
                    $push = $preferences->allows($recipient, $category, 'push');
                    DB::table('notification_deliveries')->insert(['id' => $notification->id, 'user_id' => $recipient->id,
                        'in_app' => $inApp, 'push' => $push, 'created_at' => now()]);
                    $channels = [];
                    if ($inApp) {
                        $channels[] = 'database';
                    }
                    if ($push) {
                        $channels[] = ExpoPushChannel::class;
                    }
                    if ($channels !== []) {
                        $recipient->notifyNow($notification, $channels);
                    }
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Another fanout retry already wrote this exact delivery.
        }
    }

    public static function deliveryId(User $user, string $event, string $source): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'e-syrians:'.$user->uuid.':'.$event.':'.$source)->toString();
    }
}
