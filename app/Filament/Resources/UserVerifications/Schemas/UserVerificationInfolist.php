<?php


declare(strict_types=1);

namespace App\Filament\Resources\UserVerifications\Schemas;

use Filament\Schemas\Schema;
use App\Models\UserVerification;
use Filament\Infolists\Components\TextEntry;

class UserVerificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('verifier.name')
                    ->label('Verifier'),
                TextEntry::make('user.name')
                    ->label('User'),
                TextEntry::make('cancelled_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('ip_address')
                    ->placeholder('-'),
                TextEntry::make('user_agent')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('deleted_at')
                    ->dateTime()
                    ->visible(fn (UserVerification $record): bool => $record->trashed()),
            ]);
    }
}
