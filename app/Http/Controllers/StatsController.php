<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\StatsService;

class StatsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json([
            'daily_users' => StatsService::getDailyUsersStats(),
        ]);
    }
}
