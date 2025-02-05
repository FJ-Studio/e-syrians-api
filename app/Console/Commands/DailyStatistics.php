<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
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
        // Get the current date
        $dateKey = Carbon::now()->toDateString();
        // Get the cache key
        $usersKey = config('e-syrians.cache.daily_registrants');
        // Get the statistics from the cache
        $statistics = Cache::get($usersKey, []);
        // Check if the statistics for the current date are already calculated
        if (!isset($statistics[$dateKey])) {
            $statistics[$dateKey] = [
                'registered' => User::whereDate('created_at', $dateKey)->count(),
                'verified' => User::whereDate('verified_at', $dateKey)->count(),
            ];
            // Store the updated statistics permanently
            Cache::forever($usersKey, $statistics);
            $this->info('Daily users stats have been calculated and cached successfully.');
        }
        // gender stats
        $genderKey = config('e-syrians.cache.gender');
        Cache::forever(
            $genderKey,
            [
                'male' => User::where('gender', 'm')->whereNull('verified_at')->count(),
                'female' => User::where('gender', 'f')->whereNull('verified_at')->count(),
                'verified_male' => User::where('gender', 'm')->whereNotNull('verified_at')->count(),
                'verified_female' => User::where('gender', 'f')->whereNotNull('verified_at')->count(),
                'unknown' => User::whereNull('gender')->count(),
            ]
        );
    }
}
