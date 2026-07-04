<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests\Schemas;

use Filament\Schemas\Schema;
use App\Models\FeatureRequest;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DateTimePicker;

/**
 * Edit-only form. `created_by` is deliberately absent — that value is
 * set once at submission time from the mobile / web client and admins
 * should never rewrite it (breaking that link would silently move
 * ownership between real user accounts).
 */
class FeatureRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Content')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                Section::make('Timeline')
                    ->description('The stage badge is derived from these three stamps — set them to advance a request; clear them all to send it back to "idea".')
                    ->schema([
                        DateTimePicker::make('coded_at')
                            ->label('Development started')
                            ->seconds(false)
                            ->native(false),
                        DateTimePicker::make('tested_at')
                            ->label('Testing started')
                            ->seconds(false)
                            ->native(false),
                        DateTimePicker::make('deployed_at')
                            ->label('Shipped')
                            ->seconds(false)
                            ->native(false),
                    ])
                    ->columns(3),

                Section::make('Deletion reason')
                    ->description('Recorded when this request was soft-deleted. Cleared on restore.')
                    ->schema([
                        Textarea::make('deletion_reason')
                            ->hiddenLabel()
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (?FeatureRequest $record): bool => $record !== null && $record->trashed()),
            ]);
    }
}
