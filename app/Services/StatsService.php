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
        return Cache::get(config('e-syrians.cache.age'), []);
    }
    public static function getEthnicityStats(): array
    {
        return Cache::get(config('e-syrians.cache.ethnicity'), []);
    }

    public static function getCountryStats(): array
    {
        return Cache::get(config('e-syrians.cache.country'), []);
    }
    public static function getHometownStats(): array
    {
        return Cache::get(config('e-syrians.cache.hometown'), []);
    }
    public static function getReligionStats(): array
    {
        return Cache::get(config('e-syrians.cache.religion'), []);
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
                'f' => [
                    'verified' => User::where('gender', 'f')->whereNotNull('verified_at')->count(),
                    'unverified' => User::where('gender', 'f')->whereNull('verified_at')->count()
                ],
                'm' => [
                    'verified' => User::where('gender', 'm')->whereNotNull('verified_at')->count(),
                    'unverified' =>
                    User::where('gender', 'm')->whereNull('verified_at')->count(),
                ],
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
    }
    public static function calculateEthnicityStats(): void
    {
        $ethnicityKey = config('e-syrians.cache.ethnicity');
        $ethnicityStats = new self();
        Cache::forever($ethnicityKey, $ethnicityStats->groupUsersByField('ethnicity'));
    }
    public static function calculateReligionStats(): void
    {
        // Get the cache key
        $religionKey = config('e-syrians.cache.religion');
        $religionStatistics = new self();
        Cache::forever($religionKey, $religionStatistics->groupUsersByField('religious_affiliation'));
    }
    public static function calculateCountryStats(): void
    {
        $countryKey = config('e-syrians.cache.country');
        $countryStatistics = new self();
        Cache::forever($countryKey, $countryStatistics->groupUsersByField('country'));
    }
    public static function calculateHometownStats(): void
    {
        $hometownKey = config('e-syrians.cache.hometown');
        $hometownStatistics = new self();
        Cache::forever($hometownKey, $hometownStatistics->groupUsersByField('hometown'));
    }
    public function groupUsersByField(string $field): array
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
                if (!isset($stats[$key])) {
                    $stats[$key] = [
                        'verified' => 0,
                        'unverified' => 0,
                    ];
                }

                $stats[$key][$status] = $entry->count;
            }
        }

        return $stats;
    }
}
