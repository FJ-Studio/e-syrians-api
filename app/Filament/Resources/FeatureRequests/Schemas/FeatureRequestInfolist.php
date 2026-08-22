<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests\Schemas;

use Filament\Schemas\Schema;
use App\Models\FeatureRequest;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\FontWeight;
use App\Enums\FeatureRequestStatusEnum;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;

class FeatureRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Overview')
                    ->schema([
                        TextEntry::make('title')
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->columnSpanFull(),

                        // Bind to `user.name` so Filament resolves the state
                        // through the eager-loaded relation, then reformat
                        // to include the surname (which isn't a dotted path).
                        TextEntry::make('user.name')
                            ->label('Submitted by')
                            ->formatStateUsing(fn (?string $state, FeatureRequest $record): string => trim(
                                ($state ?? '') . ' ' . ($record->user->surname ?? '')
                            ) ?: '—'),

                        TextEntry::make('status')
                            ->label('Stage')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => FeatureRequestStatusEnum::from($state)->label())
                            ->color(fn (string $state): string => FeatureRequestStatusEnum::from($state)->color()),

                        // `score`, `ups_count`, `downs_count` are appended
                        // attributes on the model — Filament resolves them
                        // via the default accessor lookup, no ->state() needed.
                        TextEntry::make('score')
                            ->label('Score (▲−▼)'),

                        TextEntry::make('ups_count')
                            ->label('Upvotes'),

                        TextEntry::make('downs_count')
                            ->label('Downvotes'),
                    ])
                    ->columns(3),

                Section::make('Description')
                    ->schema([
                        // `markdown()` preserves paragraphs and simple
                        // formatting when a user has written the request
                        // with line breaks — pure `TextEntry` would
                        // collapse them.
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->markdown()
                            ->columnSpanFull(),
                    ]),

                Section::make('Timeline')
                    ->description('Whichever timestamp is the "latest set" determines the stage. All three null → still an idea.')
                    ->schema([
                        TextEntry::make('coded_at')
                            ->label('Dev started')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('tested_at')
                            ->label('Testing started')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('deployed_at')
                            ->label('Shipped')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make('Metadata')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('id')->label('Row ID'),
                        TextEntry::make('created_at')->label('Submitted')->dateTime(),
                        TextEntry::make('updated_at')->label('Last touched')->dateTime(),
                    ])
                    ->columns(3),

                Section::make('Deletion')
                    ->schema([
                        TextEntry::make('deleted_at')
                            ->label('Deleted at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('deletion_reason')
                            ->label('Reason')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (?FeatureRequest $record): bool => $record !== null && $record->trashed()),
            ]);
    }
}
