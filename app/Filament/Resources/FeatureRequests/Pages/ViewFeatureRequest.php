<?php


declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\FeatureRequests\FeatureRequestResource;

class ViewFeatureRequest extends ViewRecord
{
    protected static string $resource = FeatureRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
