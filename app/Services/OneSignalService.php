<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Channels\Messages\OneSignalMessage;
use App\Contracts\OneSignalServiceContract;

/**
 * Thin wrapper around OneSignal's Create Notification REST endpoint.
 *
 * Endpoint: `POST https://api.onesignal.com/notifications`
 * Docs: https://documentation.onesignal.com/reference/push-notification
 *
 * Modern OneSignal split their REST API keys into two formats:
 *
 *   - v2 keys (prefix `os_v2_`) — use the `Key <key>` Authorization
 *     header AND target subscriptions via `include_subscription_ids`.
 *   - Legacy keys (older "REST API Key" string) — use `Basic <key>`
 *     and the legacy `include_player_ids` field.
 *
 * Both still work in production today, so we detect the format and
 * adapt automatically. New OneSignal apps mint v2 keys by default.
 *
 * Ported from foundhere-api with adaptations to e-syrians' Contract
 * pattern.
 */
class OneSignalService implements OneSignalServiceContract
{
    /** Modern OneSignal API host (preferred). */
    private const V2_API_URL = 'https://api.onesignal.com';

    /** Legacy host — still serves the same notification endpoint. */
    private const LEGACY_API_URL = 'https://onesignal.com/api/v1';

    public function isConfigured(): bool
    {
        return ! empty(config('services.onesignal.app_id'))
            && ! empty(config('services.onesignal.rest_api_key'));
    }

    public function sendToSubscriptionIds(array $subscriptionIds, OneSignalMessage $message): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('OneSignal not configured — skipping push send');
            return false;
        }

        if (empty($subscriptionIds)) {
            // Not an error — the user has zero registered devices, which
            // is the case for any web-only user. Log at debug so the
            // notification job's success message doesn't get a spurious
            // "warning" entry.
            Log::debug('OneSignal: no subscription IDs to send to');
            return false;
        }

        $isV2 = $this->isV2Key();
        $targetField = $isV2 ? 'include_subscription_ids' : 'include_player_ids';

        $payload = array_merge($message->toArray(), [
            'app_id' => config('services.onesignal.app_id'),
            $targetField => $subscriptionIds,
        ]);

        if ($isV2) {
            // v2 explicit target channel — defaults to push but being
            // explicit avoids ambiguity if we ever add email/SMS
            // channels to the same OneSignal app later.
            $payload['target_channel'] = 'push';
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->authHeader(),
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl() . '/notifications', $payload);

            if ($response->successful()) {
                return true;
            }

            Log::error('OneSignal send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            // Network errors / DNS failures / connection resets — we
            // swallow and log because notification sends should never
            // crash the parent request.
            Log::error('OneSignal send exception', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function isV2Key(): bool
    {
        $key = config('services.onesignal.rest_api_key');
        return is_string($key) && str_starts_with($key, 'os_v2_');
    }

    private function apiUrl(): string
    {
        return $this->isV2Key() ? self::V2_API_URL : self::LEGACY_API_URL;
    }

    private function authHeader(): string
    {
        $key = config('services.onesignal.rest_api_key');
        return ($this->isV2Key() ? 'Key ' : 'Basic ') . $key;
    }
}
