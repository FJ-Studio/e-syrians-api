<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\JsonResponse;
use App\Contracts\StatsServiceContract;

class StatsController extends Controller
{
    public function __construct(
        private readonly StatsServiceContract $statsService,
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        return ApiService::success([
            // `daily_users` is intentionally limited to the last 7 days for
            // the home-screen sparkline / weekly bar. Every other stat is
            // returned in full — the mobile bar chart renders all buckets
            // with `verified + unverified > 0` and the client is no longer
            // computing percentages, so a fixed top-N cap on the server
            // would just hide data without saving meaningful work
            // (the underlying query has no LIMIT either; `array_slice` was
            // only trimming an already-materialised array).
            'daily_users' => array_slice($this->statsService->getDailyUsersStats(), -7, 7, true),
            'gender' => $this->statsService->getGenderStats(),
            'age' => $this->statsService->getAgeStats(),
            'ethnicity' => $this->statsService->getEthnicityStats(),
            'country' => $this->statsService->getCountryStats(),
            'hometown' => $this->statsService->getHometownStats(),
            'religion' => $this->statsService->getReligionStats(),
        ]);
    }
}
