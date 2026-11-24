<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PollResultSnapshotService;

class FinalizePollResults extends Command
{
    /** @var string */
    protected $signature = 'polls:finalize-results {--dry-run : Preview eligible polls without creating snapshots}';

    /** @var string */
    protected $description = 'Freeze aggregate final results for expired polls without changing account data';

    public function handle(PollResultSnapshotService $snapshots): int
    {
        if ($this->option('dry-run')) {
            $this->line('Expired polls awaiting snapshots: '.$snapshots->awaitingSnapshots()->count());
            $this->info('Preview only. No snapshots were created.');

            return self::SUCCESS;
        }

        $result = $snapshots->finalizeExpired();
        $this->line('Snapshots created: '.$result['captured'].'; failures: '.$result['failed']);

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
