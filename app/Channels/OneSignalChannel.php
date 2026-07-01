<?php

declare(strict_types=1);

namespace App\Channels;

use Illuminate\Notifications\Notification;
use App\Channels\Messages\OneSignalMessage;
use App\Contracts\OneSignalServiceContract;

/**
 * Laravel notification channel for OneSignal push.
 *
 * Wire by adding `OneSignalChannel::class` to a notification class's
 * `via($notifiable)` return value AND implementing `toOneSignal`:
 *
 *   public function via($notifiable): array
 *   {
 *       return ['database', OneSignalChannel::class];
 *   }
 *
 *   public function toOneSignal($notifiable): ?OneSignalMessage
 *   {
 *       return OneSignalMessage::create()
 *           ->subject('Your poll just opened')
 *           ->body($poll->question)
 *           ->data(['type' => 'poll-audience', 'subject_id' => $poll->uuid]);
 *   }
 *
 * The channel:
 *   1. Calls `toOneSignal($notifiable)` — bail if null (notification
 *      opted out of push for this notifiable).
 *   2. Calls `routeNotificationForOneSignal()` on the notifiable to
 *      resolve the target subscription IDs (one per registered device).
 *   3. Hands both to `OneSignalServiceContract::sendToSubscriptionIds`.
 */
class OneSignalChannel
{
    public function __construct(
        private readonly OneSignalServiceContract $oneSignal,
    ) {
    }

    public function send(object $notifiable, Notification $notification): bool
    {
        if (! method_exists($notification, 'toOneSignal')) {
            return false;
        }

        /** @var OneSignalMessage|null $message */
        $message = $notification->toOneSignal($notifiable);

        if (! $message instanceof OneSignalMessage) {
            return false;
        }

        $routing = $notifiable->routeNotificationFor('onesignal', $notification);

        if (empty($routing) || ! is_array($routing)) {
            return false;
        }

        return $this->oneSignal->sendToSubscriptionIds($routing, $message);
    }
}
