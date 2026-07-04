<?php


declare(strict_types=1);

namespace App\Filament\Resources\Polls\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Polls\PollResource;

class CreatePoll extends CreateRecord
{
    protected static string $resource = PollResource::class;
}
