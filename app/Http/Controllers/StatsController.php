<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use App\Services\StatsService;

class StatsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return ApiService::success([
            'daily_users' => array_slice(StatsService::getDailyUsersStats(), -7, 7, true),
            'gender' => StatsService::getGenderStats(),
            'age' => StatsService::getAgeStats(),
            'ethnicity' => StatsService::getEthnicityStats(),
            'country' => array_slice(StatsService::getCountryStats(), 0, 10, true),
            'hometown' => StatsService::getHometownStats(),
            'religion' => StatsService::getReligionStats(),
        ]);
    }
}
