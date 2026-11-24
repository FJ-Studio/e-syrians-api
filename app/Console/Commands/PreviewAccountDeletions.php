<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PreviewAccountDeletions extends Command
{
    /** @var string */
    protected $signature = 'accounts:preview-deletions';

    /** @var string */
    protected $description = 'Preview permanent-deletion eligibility without deleting data or calling providers';

    public function handle(): int
    {
        $eligible = User::dueForPermanentDeletion();
        $scheduled = (clone $eligible)->whereNotNull('deletion_scheduled_for')->count();
        $legacy = (clone $eligible)->whereNull('deletion_scheduled_for')->count();

        $this->line('Eligible accounts: '.($scheduled + $legacy));
        $this->line('Scheduled deadline reached: '.$scheduled);
        $this->line('Legacy soft-deletion grace period reached: '.$legacy);
        $this->info('Preview only. No records were deleted.');

        return self::SUCCESS;
    }
}
