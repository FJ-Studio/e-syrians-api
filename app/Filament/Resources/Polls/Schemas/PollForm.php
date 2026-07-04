<?php


declare(strict_types=1);

namespace App\Filament\Resources\Polls\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;

class PollForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('question')
                    ->required(),
                DateTimePicker::make('start_date')
                    ->required(),
                DateTimePicker::make('end_date')
                    ->required(),
                TextInput::make('max_selections')
                    ->required()
                    ->numeric()
                    ->default(1),
                Toggle::make('audience_can_add_options')
                    ->required(),
                Select::make('reveal_results')
                    ->options([
            'before-voting' => 'Before voting',
            'after-voting' => 'After voting',
            'after-expiration' => 'After expiration',
        ])
                    ->default('before-voting')
                    ->required(),
                Toggle::make('voters_are_visible')
                    ->required(),
                TextInput::make('created_by')
                    ->required()
                    ->numeric(),
                Textarea::make('deletion_reason')
                    ->columnSpanFull(),
                Toggle::make('is_private')
                    ->required(),
                Toggle::make('audience_only')
                    ->required(),
            ]);
    }
}
