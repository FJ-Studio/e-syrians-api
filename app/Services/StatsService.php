<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsService
{
    public static function getDailyUsersStats(): array
    {
        return Cache::get(config('e-syrians.cache.daily_registrants'), []);
    }
    public static function getGenderStats(): array
    {
        return Cache::get(config('e-syrians.cache.gender'), []);
    }
    public static function getAgeStats(): array
    {
        return Cache::get('e-syrians.cache.age', []);
    }
    public static function calculateDailyUsersStats(): void
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
        }
    }
    public static function calculateGenderStats(): void
    {
        // Get the cache key
        $genderKey = config('e-syrians.cache.gender');
        Cache::forever(
            $genderKey,
            [
                'unverified_m' => User::where('gender', 'm')->whereNull('verified_at')->count(),
                'unverified_f' => User::where('gender', 'f')->whereNull('verified_at')->count(),
                'verified_m' => User::where('gender', 'm')->whereNotNull('verified_at')->count(),
                'verified_f' => User::where('gender', 'f')->whereNotNull('verified_at')->count(),
                'unknown' => User::whereNull('gender')->count(),
            ]
        );
    }
    public static function calculateAgeStats(): void
    {
        $ageGroups = [
            '1-15' => [1, 15],
            '16-30' => [16, 30],
            '31-45' => [21, 45],
            '46-60' => [46, 60],
            '61-75' => [61, 75],
            '76-90' => [76, 90],
            '91+' => [61, 200],
        ];

        $ageStatistics = [
            'verified' => [],
            'unverified' => [],
        ];

        foreach ($ageGroups as $label => [$min, $max]) {
            $ageStatistics['verified'][$label] = User::whereBetween(
                DB::raw('TIMESTAMPDIFF(YEAR, birth_date, CURDATE())'),
                [$min, $max]
            )->whereNotNull('verified_at')->count();

            $ageStatistics['unverified'][$label] = User::whereBetween(
                DB::raw('TIMESTAMPDIFF(YEAR, birth_date, CURDATE())'),
                [$min, $max]
            )->whereNull('verified_at')->count();
        }

        Cache::forever('e-syrians.cache.age', $ageStatistics);
    }
}
