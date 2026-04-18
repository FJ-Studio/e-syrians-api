<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use App\Services\BigQueryService;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class LogPollVoteToBigQuery implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly int $userId,
        private readonly int $pollId,
        private readonly array $optionIds,
        private readonly ?string $ipAddress,
        private readonly ?string $userAgent,
    ) {
    }

    public function handle(BigQueryService $bigQuery): void
    {
        if (! $bigQuery->isEnabled()) {
            return;
        }

        $now = now()->toIso8601String();

        $rows = [];
        foreach ($this->optionIds as $optionId) {
            $rows[] = [
                'event_id' => (string) Str::uuid(),
                'user_id' => $this->userId,
                'poll_id' => $this->pollId,
                'option_id' => $optionId,
                'ip_address' => $this->ipAddress,
                'user_agent' => $this->userAgent,
                'occurred_at' => $now,
            ];
        }

        $bigQuery->insertBatch('poll_votes', $rows);
    }
}
