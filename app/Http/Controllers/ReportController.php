<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\JsonResponse;
use App\Contracts\ReportServiceContract;
use App\Http\Requests\Reports\StoreReportRequest;

class ReportController extends Controller
{
    public function __construct(private readonly ReportServiceContract $reports)
    {
    }

    public function store(StoreReportRequest $request): JsonResponse
    {
        $report = $this->reports->submit($request->user(), $request->validated());

        return ApiService::success(
            ['id' => $report->id],
            $report->wasRecentlyCreated ? 'report_submitted' : 'report_already_submitted',
            $report->wasRecentlyCreated ? 201 : 200,
        );
    }
}
