<?php


declare(strict_types=1);

namespace App\Filament\Resources\Polls\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\Polls\PollResource;

class ViewPoll extends ViewRecord
{
    protected static string $resource = PollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
