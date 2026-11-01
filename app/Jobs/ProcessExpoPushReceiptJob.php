<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use App\Channels\Messages\ExpoPushMessage;
use App\Contracts\ExpoPushServiceContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessExpoPushReceiptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 300];

    public function __construct(
        public string $receiptId,
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
        $device = Device::query()->routable()->whereKey($this->deviceId)
            ->where('user_id', $this->userId)->where('registration_version', $this->version)->first();
        if (! $device) {
            return;
        }
        $receipt = $push->receipt($this->receiptId);
        if ($receipt === null) {
            $this->release(60);
            return;
        }
        $error = $receipt['details']['error'] ?? null;
        if ($error === 'DeviceNotRegistered') {
            Device::query()->whereKey($this->deviceId)->where('user_id', $this->userId)
                ->where('registration_version', $this->version)->update(['disabled_at' => now()]);
        } elseif ($error === 'MessageRateExceeded' && $this->generation < 2) {
            dispatch(new SendExpoPushJob($this->deviceId, $this->userId, $this->version, $this->notificationId, $this->message, $this->generation + 1))
                ->delay(now()->addSeconds($this->generation === 0 ? 60 : 300));
        }
    }
}
