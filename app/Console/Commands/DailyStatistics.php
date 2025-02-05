<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StatsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DailyStatistics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:daily-statistics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will calculate the daily users stats and cache them.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        StatsService::calculateDailyUsersStats();
        StatsService::calculateGenderStats();
        StatsService::calculateAgeStats();
        StatsService::calculateEthnicityStats();
        StatsService::calculateCountryStats();
        StatsService::calculateHometownStats();
    }
}
