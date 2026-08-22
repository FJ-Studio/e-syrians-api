<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Device;
use Illuminate\Support\Facades\Date;
use App\Contracts\DeviceServiceContract;

class DeviceService implements DeviceServiceContract
{
    public function registerDevice(User $user, array $payload): Device
    {
        $subscriptionId = $payload['subscription_id'];

        // `updateOrCreate` on the unique `subscription_id` handles all
        // three cases in one query:
        //   - brand-new device → row created
        //   - same user re-registering (token refresh, settings flip)
        //     → row updated with refreshed `last_seen_at`
        //   - device was previously someone else's → row's user_id
        //     reassigned to the current user; the previous owner no
        //     longer receives notifications targeted at that device.
        //     The mobile client at logout calls DELETE first, so the
        //     re-link path is mostly a safety net for app crashes or
        //     misbehaving SDK builds.
        return Device::updateOrCreate(
            ['subscription_id' => $subscriptionId],
            [
                'user_id' => $user->id,
                'platform' => $payload['platform'],
                'model' => $payload['model'] ?? null,
                'last_seen_at' => Date::now(),
            ],
        );
    }

    public function unregisterDevice(User $user, string $subscriptionId): void
    {
        // Scoped by user_id so a stolen subscription_id can't be used to
        // unregister someone else's device. The lookup returns null
        // silently when the device isn't ours — caller treats both
        // "not found" and "deleted" as success per the spec.
        Device::query()
            ->where('user_id', $user->id)
            ->where('subscription_id', $subscriptionId)
            ->delete();
    }
}
