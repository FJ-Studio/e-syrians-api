<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use App\Models\PollAudienceRule;
use App\Services\BigQueryService;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SyncPollAudienceRulesToBigQuery implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly int $pollId,
    ) {
    }

    public function handle(BigQueryService $bigQuery): void
    {
        if (! $bigQuery->isEnabled()) {
            return;
        }

        $rules = PollAudienceRule::where('poll_id', $this->pollId)->get();
        $now = now()->toIso8601String();

        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = [
                'poll_id' => $rule->poll_id,
                'criterion' => $rule->criterion,
                'value' => $rule->value,
                'synced_at' => $now,
            ];
        }

        if (! empty($rows)) {
            $bigQuery->insertBatch('poll_audience_rules', $rows);
        }
    }
}
