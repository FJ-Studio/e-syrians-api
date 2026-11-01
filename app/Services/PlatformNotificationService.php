<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Ramsey\Uuid\Uuid;
use App\Channels\ExpoPushChannel;
use Illuminate\Support\Facades\DB;
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
                    $channels = $notification->via($recipient);
                    $inApp = in_array('database', $channels, true);
                    $push = in_array(ExpoPushChannel::class, $channels, true);
                    DB::table('notification_deliveries')->insert(['id' => $notification->id, 'user_id' => $recipient->id,
                        'in_app' => $inApp, 'push' => $push, 'created_at' => now()]);
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
