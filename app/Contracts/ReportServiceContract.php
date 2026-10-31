<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use App\Models\Report;
use App\Enums\ReportStatusEnum;

interface ReportServiceContract
{
    /** @param array{reportable_type: string, reportable_id: int|string, reason: string, details?: ?string} $data */
    public function submit(User $reporter, array $data): Report;

    public function review(Report $report, User $reviewer, ReportStatusEnum $status, ?string $notes): void;
}
