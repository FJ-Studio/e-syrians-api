<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Contracts\AccountDeletionServiceContract;

/**
 * Runs daily (see `routes/console.php`). Sweeps every user whose
 * explicit deadline has passed, plus legacy soft-deleted users whose
 * grace period has elapsed, and hard-deletes via AccountDeletionService.
 *
 * One transaction per user — a single malformed row must not halt
 * the whole batch. Failures are logged and reported but do not
 * re-raise, so a stuck row can be triaged manually without the
 * queue worker retrying indefinitely.
 */
class HardDeleteExpiredAccountsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(AccountDeletionServiceContract $accountDeletionService): void
    {
        // chunkById (not chunk / get) so:
        //   - memory stays bounded at 50 hydrated User models at a
        //     time (a stale ->get() could OOM once expired users
        //     grow into the tens of thousands),
        //   - the paging cursor is stable even though the inner
        //     loop deletes rows underneath us — chunkById walks by
        //     `id > lastId` instead of OFFSET, so freshly-deleted
        //     rows can't shift the pagination window and cause the
        //     job to skip subsequent users.
        //
        // Each row's cascade runs inside its own transaction + try/
        // catch so a single malformed row logs and moves on rather
        // than halting the whole batch.
        User::dueForPermanentDeletion()
            ->chunkById(50, function ($users) use ($accountDeletionService): void {
                foreach ($users as $user) {
                    try {
                        DB::transaction(function () use ($accountDeletionService, $user): void {
                            // The batch is only a list of candidates. Cancellation,
                            // restoration or rescheduling may have happened since
                            // it was loaded; lock and recheck before provider erasure.
                            $current = User::dueForPermanentDeletion()->lockForUpdate()->find($user->id);
                            if ($current !== null) {
                                $accountDeletionService->hardDeleteAccount($current);
                            }
                        });
                    } catch (Throwable $e) {
                        Log::error('HardDeleteExpiredAccountsJob: failed to hard-delete user', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                        report($e);
                    }
                }
            });
    }
}
