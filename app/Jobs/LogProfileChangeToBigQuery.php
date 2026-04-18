<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Support\Str;
use App\Models\ProfileUpdate;
use Illuminate\Bus\Queueable;
use App\Services\BigQueryService;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class LogProfileChangeToBigQuery implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly ProfileUpdate $profileUpdate,
    ) {
    }

    public function handle(BigQueryService $bigQuery): void
    {
        if (! $bigQuery->isEnabled()) {
            return;
        }

        $changes = $this->profileUpdate->changes ?? [];
        $eventId = (string) Str::uuid();

        if (empty($changes)) {
            // Even if no field actually changed, log the attempt (e.g. blocked)
            $bigQuery->insert('profile_changes', [
                'event_id' => $eventId,
                'user_id' => $this->profileUpdate->user_id,
                'change_type' => $this->profileUpdate->change_type,
                'field_name' => null,
                'old_value' => null,
                'new_value' => null,
                'ip_address' => $this->profileUpdate->ip_address,
                'user_agent' => $this->profileUpdate->user_agent,
                'request_source' => $this->profileUpdate->request_source,
                'blocked' => $this->profileUpdate->blocked,
                'block_reason' => $this->profileUpdate->block_reason,
                'occurred_at' => $this->profileUpdate->created_at->toIso8601String(),
            ]);

            return;
        }

        // Denormalize: one row per field changed
        $rows = [];
        foreach ($changes as $field => $diff) {
            $rows[] = [
                'event_id' => $eventId,
                'user_id' => $this->profileUpdate->user_id,
                'change_type' => $this->profileUpdate->change_type,
                'field_name' => $field,
                'old_value' => isset($diff['old']) ? (string) $diff['old'] : null,
                'new_value' => isset($diff['new']) ? (string) $diff['new'] : null,
                'ip_address' => $this->profileUpdate->ip_address,
                'user_agent' => $this->profileUpdate->user_agent,
                'request_source' => $this->profileUpdate->request_source,
                'blocked' => $this->profileUpdate->blocked,
                'block_reason' => $this->profileUpdate->block_reason,
                'occurred_at' => $this->profileUpdate->created_at->toIso8601String(),
            ];
        }

        $bigQuery->insertBatch('profile_changes', $rows);
    }
}
