<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StatsServiceContract;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsService implements StatsServiceContract
{
    public function getDailyUsersStats(): array
    {
        return Cache::get(config('e-syrians.cache.daily_registrants'), self::calculateDailyUsersStats());
    }

    public function getGenderStats(): array
    {
        return Cache::get(config('e-syrians.cache.gender'), self::calculateGenderStats());
    }

    public function getAgeStats(): array
    {
        return Cache::get(config('e-syrians.cache.age'), self::calculateAgeStats());
    }

    public function getEthnicityStats(): array
    {
        return Cache::get(config('e-syrians.cache.ethnicity'), self::calculateEthnicityStats());
    }

    public function getCountryStats(): array
    {
        return Cache::get(config('e-syrians.cache.country'), self::calculateCountryStats());
    }

    public function getHometownStats(): array
    {
        return Cache::get(config('e-syrians.cache.hometown'), self::calculateHometownStats());
    }

    public function getReligionStats(): array
    {
        return Cache::get(config('e-syrians.cache.religion'), self::calculateReligionStats());
    }

    public static function calculateDailyUsersStats()
    {
        // Get the current date
        $dateKey = Carbon::now()->toDateString();
        // Get the cache key
        $usersKey = config('e-syrians.cache.daily_registrants');
        // Get the statistics from the cache
        $statistics = Cache::get($usersKey, []);
        // Check if the statistics for the current date are already calculated
        $statistics[$dateKey] = [
            'registered' => User::whereDate('created_at', $dateKey)->count(),
            'verified' => User::whereDate('verified_at', $dateKey)->count(),
        ];
        // Store the updated statistics permanently
        Cache::forever($usersKey, $statistics);

        return $statistics;
    }

    public static function calculateGenderStats()
    {
        // Get the cache key
        $genderKey = config('e-syrians.cache.gender');
        $genderStats = [
            'f' => [
                'verified' => User::where('gender', 'f')->whereNotNull('verified_at')->count(),
                'unverified' => User::where('gender', 'f')->whereNull('verified_at')->count(),
            ],
            'm' => [
                'verified' => User::where('gender', 'm')->whereNotNull('verified_at')->count(),
                'unverified' => User::where('gender', 'm')->whereNull('verified_at')->count(),
            ],
            'unknown' => [
                'verified' => User::whereNull('gender')->whereNotNull('verified_at')->count(),
                'unverified' => User::whereNull('gender')->whereNull('verified_at')->count(),
            ],
        ];
        // Store the statistics in the cache
        Cache::forever(
            $genderKey,
            $genderStats
        );

        return $genderStats;
    }

    public static function calculateAgeStats()
    {
        $ageGroups = [
            '1-15' => [1, 15],
            '16-30' => [16, 30],
            '31-45' => [31, 45],
            '46-60' => [46, 60],
            '61-75' => [61, 75],
            '76-90' => [76, 90],
            '91+' => [91, 200],
        ];

        foreach ($ageGroups as $label => [$min, $max]) {
            $ageStatistics[$label]['verified'] = User::whereBetween(
                DB::raw('TIMESTAMPDIFF(YEAR, birth_date, CURDATE())'),
                [$min, $max]
            )->whereNotNull('verified_at')->count();

            $ageStatistics[$label]['unverified'] = User::whereBetween(
                DB::raw('TIMESTAMPDIFF(YEAR, birth_date, CURDATE())'),
                [$min, $max]
            )->whereNull('verified_at')->count();
        }

        Cache::forever(config('e-syrians.cache.age'), $ageStatistics);

        return $ageStatistics;
    }

    public static function calculateEthnicityStats()
    {
        $ethnicityKey = config('e-syrians.cache.ethnicity');
        $ethnicityStats = (new self)->groupUsersByField('ethnicity', true);
        Cache::forever($ethnicityKey, $ethnicityStats);

        return $ethnicityStats;
    }

    public static function calculateReligionStats()
    {
        // Get the cache key
        $religionKey = config('e-syrians.cache.religion');
        $religionStatistics = (new self)->groupUsersByField('religious_affiliation', true);
        Cache::forever($religionKey, $religionStatistics);

        return $religionStatistics;
    }

    public static function calculateCountryStats()
    {
        $countryKey = config('e-syrians.cache.country');
        $countryStatistics = (new self)->groupUsersByField('country', true);
        Cache::forever($countryKey, $countryStatistics);

        return $countryStatistics;
    }

    public static function calculateHometownStats()
    {
        $hometownKey = config('e-syrians.cache.hometown');
        $hometownStatistics = (new self)->groupUsersByField('hometown', true);
        Cache::forever($hometownKey, $hometownStatistics);

        return $hometownStatistics;
    }

    public function groupUsersByField(string $field, bool $sort = false): array
    {
        $data = User::select(
            $field,
            DB::raw('COUNT(*) as count'),
            DB::raw('CASE WHEN verified_at IS NOT NULL THEN "verified" ELSE "unverified" END as verification_status')
        )
            ->groupBy($field, 'verification_status')
            ->get()
            ->groupBy('verification_status'); // Group by verified/unverified

        $stats = [];

        foreach (['verified', 'unverified'] as $status) {
            foreach ($data[$status] ?? [] as $entry) {
                $key = $entry->$field ?? 'unknown';

                // Ensure both verified & unverified keys exist for each value
                if (! isset($stats[$key])) {
                    $stats[$key] = [
                        'verified' => 0,
                        'unverified' => 0,
                    ];
                }

                $stats[$key][$status] = $entry->count;
            }
        }

        if ($sort) {
            uasort($stats, function ($a, $b) {
                return ($b['verified'] + $b['unverified']) <=> ($a['verified'] + $a['unverified']);
            });
        }

        return $stats;
    }
}
