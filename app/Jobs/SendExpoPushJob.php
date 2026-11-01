<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Device;
use Laravel\Pennant\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\InteractsWithQueue;
use App\Channels\Messages\ExpoPushMessage;
use App\Contracts\ExpoPushServiceContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\NotificationPreferenceService;
use App\Exceptions\InvalidExpoPushTokenException;
use App\Services\NotificationDeliverySettingsService;

/** Queue only identifiers and privacy-safe routing data, never the actual push token. */
class SendExpoPushJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $deviceId,
        public int $userId,
        public int $version,
        public string $notificationId,
        public ExpoPushMessage $message,
        public int $generation = 0
    ) {
    }

    public function handle(ExpoPushServiceContract $push): void
    {
        if (! $push->isEnabled()) {
            return;
        }
        // Refresh at execution, including retries after a failed worker job.
        Feature::flushCache();
        // Legacy jobs without an exact event cannot safely bypass the per-event policy.
        if (! resolve(NotificationDeliverySettingsService::class)->allows($this->message->event ?? null, 'push')) {
            return;
        }
        DB::transaction(function () use ($push): void {
            $device = Device::query()->routable()->whereKey($this->deviceId)
                ->where('user_id', $this->userId)->where('registration_version', $this->version)
                ->lockForUpdate()->first();
            $key = 'expo-push:'.$this->notificationId.':'.$this->deviceId.':'.$this->version.':'.$this->generation;
            if (! $device || Cache::has($key) || ! $device->expo_push_token) {
                return;
            }
            if (! resolve(NotificationPreferenceService::class)->allows($device->user, $this->message->notificationCategory(), 'push')) {
                return;
            }
            try {
                $receiptId = $push->send($device->expo_push_token, $this->message);
            } catch (InvalidExpoPushTokenException) {
                $device->update(['disabled_at' => now()]);
                return;
            }
            Cache::put($key, true, now()->addDay());
            dispatch(new ProcessExpoPushReceiptJob($receiptId, $this->deviceId, $this->userId, $this->version, $this->notificationId, $this->message, $this->generation))
                ->delay(now()->addSeconds(900))->afterCommit();
        });
    }
}
