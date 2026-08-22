<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\FeatureRequests\FeatureRequestResource;

/**
 * Deliberately no header actions — feature requests are only created
 * by end users through the public API. The admin surface is
 * moderation-only (stage changes, soft-delete, restore).
 */
class ListFeatureRequests extends ListRecords
{
    protected static string $resource = FeatureRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
