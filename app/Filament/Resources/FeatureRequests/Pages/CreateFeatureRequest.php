<?php


declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\FeatureRequests\FeatureRequestResource;

class CreateFeatureRequest extends CreateRecord
{
    protected static string $resource = FeatureRequestResource::class;
}
