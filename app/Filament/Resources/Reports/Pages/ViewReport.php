<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports\Pages;

use Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\Reports\ReportResource;

class ViewReport extends ViewRecord
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return ReportResource::reviewActions();
    }
}
