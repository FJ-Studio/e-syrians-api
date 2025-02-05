<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

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
}
