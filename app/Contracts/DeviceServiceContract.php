<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use App\Models\Device;

interface DeviceServiceContract
{
    /**
     * Register (or re-register) a device's OneSignal subscription against
     * a user. Idempotent on `(user_id, subscription_id)` — repeat calls
     * with the same payload do not create duplicates.
     *
     * Handles the cross-user re-link case: if a device that was
     * previously registered to user A signs in as user B, the existing
     * row is reassigned to B rather than rejected. This is the correct
     * behaviour for a shared-device scenario (kid uses the parent's
     * phone, etc.) — the device should only ever receive notifications
     * for the user currently signed in.
     *
     * @param  array{subscription_id:string, platform:string, model?:string|null}  $payload
     */
    public function registerDevice(User $user, array $payload): Device;

    /**
     * Unregister a device — used at sign-out. Best-effort: if the
     * subscription is not registered (404 logical) we still return
     * normally because the desired end state ("device not registered
     * to this user") is already true.
     *
     * Only deletes when the device currently belongs to the given user.
     * Cross-user deletes are silently no-op'd to prevent a stolen
     * subscription id from being used to unregister another user's
     * device.
     */
    public function unregisterDevice(User $user, string $subscriptionId): void;
}
