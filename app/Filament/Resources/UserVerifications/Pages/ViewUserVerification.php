<?php


declare(strict_types=1);

namespace App\Filament\Resources\UserVerifications\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\UserVerifications\UserVerificationResource;

class ViewUserVerification extends ViewRecord
{
    protected static string $resource = UserVerificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
