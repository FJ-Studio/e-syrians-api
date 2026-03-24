<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\StatsServiceContract;
use App\Services\ApiService;

class StatsController extends Controller
{
    public function __construct(
        private readonly StatsServiceContract $statsService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return ApiService::success([
            'daily_users' => array_slice($this->statsService->getDailyUsersStats(), -7, 7, true),
            'gender' => $this->statsService->getGenderStats(),
            'age' => $this->statsService->getAgeStats(),
            'ethnicity' => $this->statsService->getEthnicityStats(),
            'country' => array_slice($this->statsService->getCountryStats(), 0, 10, true),
            'hometown' => $this->statsService->getHometownStats(),
            'religion' => $this->statsService->getReligionStats(),
        ]);
    }
}
