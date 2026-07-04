<?php


declare(strict_types=1);

namespace App\Filament\Resources\UserVerifications\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\UserVerifications\UserVerificationResource;

class CreateUserVerification extends CreateRecord
{
    protected static string $resource = UserVerificationResource::class;
}
