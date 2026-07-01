<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Channels\Messages\OneSignalMessage;

interface OneSignalServiceContract
{
    /**
     * Is the SDK configured with both an app id AND a REST API key?
     * Used by the channel as a pre-flight check so we don't waste a
     * round-trip on a misconfigured environment.
     */
    public function isConfigured(): bool;

    /**
     * Send a push to specific OneSignal subscription IDs (modern term
     * for "player IDs"). The channel resolves these from
     * `User::routeNotificationForOneSignal` for the notifiable user.
     *
     * Returns true on a successful API call, false on misconfiguration
     * or non-2xx response. Failures are logged but never thrown — push
     * is fire-and-forget; a failed notification shouldn't crash the
     * request that triggered it.
     *
     * @param  array<int, string>  $subscriptionIds
     */
    public function sendToSubscriptionIds(array $subscriptionIds, OneSignalMessage $message): bool;
}
