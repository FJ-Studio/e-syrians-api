<?php


declare(strict_types=1);

namespace App\Filament\Resources\UserVerifications\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\UserVerifications\UserVerificationResource;

class ListUserVerifications extends ListRecords
{
    protected static string $resource = UserVerificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
