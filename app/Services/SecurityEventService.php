<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\DB;
use App\Jobs\FanOutPlatformNotificationJob;

class SecurityEventService
{
    public function recordSignIn(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $installation = request()->header('X-Installation-ID');
            $new = null;
            if (is_string($installation) && Str::isUuid($installation)) {
                $hash = hash('sha256', strtolower($installation));
                $known = DB::table('security_known_devices')->where('user_id', $user->id)->where('installation_hash', $hash);
                $new = ! $known->exists();
                if ($new) {
                    DB::table('security_known_devices')->insert(['user_id' => $user->id, 'installation_hash' => $hash,
                        'created_at' => now(), 'updated_at' => now()]);
                } else {
                    $known->update(['updated_at' => now()]);
                }
            }
            // Never infer trusted physical identity from a spoofable/common User-Agent.
            // Clients without an installation ID get an alert for every successful sign-in.
            $this->record($user, 'security_new_signin', $new, $new !== false);
        });
    }

    public function record(User $user, string $event, ?bool $newDevice = null, bool $notify = true): SecurityEvent
    {
        $record = SecurityEvent::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'event' => $event, 'new_device' => $newDevice,
            'device_info' => mb_substr((string) request()->header('X-Device-Info', request()->userAgent() ?? ''), 0, 255),
            'ip_address' => request()->ip()]);
        // Audit storage is unconditional. Delivery is configurable and can be disabled.
        if ($notify && config('services.notification_events_enabled', true)) {
            dispatch(new FanOutPlatformNotificationJob($event, $record->id))->afterCommit();
        }
        return $record;
    }
}
