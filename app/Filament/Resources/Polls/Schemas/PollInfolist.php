<?php


declare(strict_types=1);

namespace App\Filament\Resources\Polls\Schemas;

use App\Models\Poll;
use Filament\Schemas\Schema;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;

class PollInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('question'),
                TextEntry::make('start_date')
                    ->dateTime(),
                TextEntry::make('end_date')
                    ->dateTime(),
                TextEntry::make('max_selections')
                    ->numeric(),
                IconEntry::make('audience_can_add_options')
                    ->boolean(),
                TextEntry::make('reveal_results')
                    ->badge(),
                IconEntry::make('voters_are_visible')
                    ->boolean(),
                TextEntry::make('created_by')
                    ->numeric(),
                TextEntry::make('deletion_reason')
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
                    ->visible(fn (Poll $record): bool => $record->trashed()),
                IconEntry::make('is_private')
                    ->boolean(),
                IconEntry::make('audience_only')
                    ->boolean(),
            ]);
    }
}
