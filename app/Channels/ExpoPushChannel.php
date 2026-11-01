<?php

declare(strict_types=1);

namespace App\Channels;

use App\Models\User;
use App\Jobs\SendExpoPushJob;
use App\Channels\Messages\ExpoPushMessage;
use Illuminate\Notifications\Notification;
use App\Services\NotificationDeliverySettingsService;

class ExpoPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toExpoPush')) {
            return;
        }
        $message = $notification->toExpoPush($notifiable);
        if (! $message instanceof ExpoPushMessage
            || ! resolve(NotificationDeliverySettingsService::class)->allows($message->event ?? null, 'push')) {
            return;
        }
        $id = $notification->id;
        foreach ($notifiable->devices()->routable()->get() as $device) {
            dispatch(new SendExpoPushJob(
                $device->id,
                $notifiable->id,
                $device->registration_version,
                $id,
                new ExpoPushMessage($message->data, $device->locale, $message->notificationCategory(), $message->event)
            ))->afterCommit();
        }
    }
}
