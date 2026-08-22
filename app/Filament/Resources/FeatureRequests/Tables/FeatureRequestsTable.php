<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests\Tables;

use Filament\Tables\Table;
use Filament\Actions\Action;
use App\Models\FeatureRequest;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Date;
use Filament\Actions\BulkActionGroup;
use App\Enums\FeatureRequestStatusEnum;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\ForceDeleteBulkAction;
use App\Contracts\FeatureRequestServiceContract;

class FeatureRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn (FeatureRequest $record): string => $record->title),
                TextColumn::make('user.name')
                    ->label('Submitter')
                    ->formatStateUsing(fn (FeatureRequest $record): string => trim(
                        ($record->user->name ?? '') . ' ' . ($record->user->surname ?? '')
                    ) ?: '—')
                    ->searchable(['users.name', 'users.surname'])
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Stage')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FeatureRequestStatusEnum::from($state)->label())
                    ->color(fn (string $state): string => FeatureRequestStatusEnum::from($state)->color()),
                // Score = ups − downs. Sorting pushes the arithmetic into
                // the SQL layer using the withCount aliases added by
                // FeatureRequestResource::getEloquentQuery.
                TextColumn::make('score')
                    ->label('Score')
                    ->numeric()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('(COALESCE(ups_aggregate_count, 0) - COALESCE(downs_aggregate_count, 0)) ' . $direction))
                    ->alignEnd(),
                TextColumn::make('ups_aggregate_count')
                    ->label('▲')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('downs_aggregate_count')
                    ->label('▼')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->tooltip(fn (FeatureRequest $record): string => $record->created_at?->toDateTimeString() ?? '')
                    ->sortable(),
                TextColumn::make('coded_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tested_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deployed_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Status filter reuses the FeatureRequest::scopeAtStage
                // scope so it stays in lockstep with the public API's
                // `?status=` query param.
                SelectFilter::make('status')
                    ->label('Stage')
                    ->options(collect(FeatureRequestStatusEnum::cases())
                        ->mapWithKeys(fn (FeatureRequestStatusEnum $case): array => [$case->value => $case->label()])
                        ->all())
                    // Filament invokes the closure with a generic Builder,
                    // so the `atStage()` scope isn't visible to PHPStan
                    // without narrowing the type to `Builder<FeatureRequest>`.
                    ->query(function (Builder $query, array $data): Builder {
                        /** @var Builder<FeatureRequest> $query */
                        $value = $data['value'] ?? null;
                        if (blank($value)) {
                            return $query;
                        }

                        return $query->atStage(FeatureRequestStatusEnum::from((string) $value));
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Quick-mark actions. Each preserves any prior stamps
                // that are still valid ("mark shipped" from in_testing
                // keeps coded_at and tested_at as-is) and clears any
                // later ones. Delegates to the service so the model
                // access rules (trashed guard) live in one place.
                ActionGroup::make([
                    Action::make('mark_in_development')
                        ->label('Mark in development')
                        ->icon(Heroicon::OutlinedCodeBracket)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (FeatureRequest $record): bool => ! $record->trashed()
                            && FeatureRequestStatusEnum::fromFeatureRequest($record) !== FeatureRequestStatusEnum::InDevelopment)
                        ->action(function (FeatureRequest $record): void {
                            resolve(FeatureRequestServiceContract::class)->setTimeline($record->id, [
                                'coded_at' => $record->coded_at ?? Date::now(),
                                'tested_at' => null,
                                'deployed_at' => null,
                            ]);
                        }),
                    Action::make('mark_in_testing')
                        ->label('Mark in testing')
                        ->icon(Heroicon::OutlinedBeaker)
                        ->color('info')
                        ->requiresConfirmation()
                        ->visible(fn (FeatureRequest $record): bool => ! $record->trashed()
                            && FeatureRequestStatusEnum::fromFeatureRequest($record) !== FeatureRequestStatusEnum::InTesting)
                        ->action(function (FeatureRequest $record): void {
                            $now = Date::now();
                            resolve(FeatureRequestServiceContract::class)->setTimeline($record->id, [
                                'coded_at' => $record->coded_at ?? $now,
                                'tested_at' => $record->tested_at ?? $now,
                                'deployed_at' => null,
                            ]);
                        }),
                    Action::make('mark_shipped')
                        ->label('Mark shipped')
                        ->icon(Heroicon::OutlinedRocketLaunch)
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (FeatureRequest $record): bool => ! $record->trashed()
                            && FeatureRequestStatusEnum::fromFeatureRequest($record) !== FeatureRequestStatusEnum::Shipped)
                        ->action(function (FeatureRequest $record): void {
                            $now = Date::now();
                            resolve(FeatureRequestServiceContract::class)->setTimeline($record->id, [
                                'coded_at' => $record->coded_at ?? $now,
                                'tested_at' => $record->tested_at ?? $now,
                                'deployed_at' => $record->deployed_at ?? $now,
                            ]);
                        }),
                    Action::make('revert_to_idea')
                        ->label('Revert to idea')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('Clears all timeline stamps. The row will be back at "idea" and any history of when it was coded / tested / deployed will be lost.')
                        ->visible(fn (FeatureRequest $record): bool => ! $record->trashed()
                            && FeatureRequestStatusEnum::fromFeatureRequest($record) !== FeatureRequestStatusEnum::Idea)
                        ->action(function (FeatureRequest $record): void {
                            resolve(FeatureRequestServiceContract::class)->setTimeline($record->id, [
                                'coded_at' => null,
                                'tested_at' => null,
                                'deployed_at' => null,
                            ]);
                        }),
                ])
                    ->label('Change stage')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('primary')
                    ->button(),
                ViewAction::make(),
                EditAction::make(),
                // Soft-delete with a required reason — never lose the "why"
                // from the audit trail. Uses the service so trashed guards
                // + cache busts run consistently.
                Action::make('delete_with_reason')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Soft-delete this feature request')
                    ->modalDescription('The row will be hidden from public listings but retained for audit. Restoring it later will clear the reason.')
                    ->schema([
                        Textarea::make('deletion_reason')
                            ->label('Reason (recorded on the row)')
                            ->required()
                            ->minLength(3)
                            ->rows(3),
                    ])
                    ->visible(fn (FeatureRequest $record): bool => ! $record->trashed())
                    ->action(function (FeatureRequest $record, array $data): void {
                        resolve(FeatureRequestServiceContract::class)
                            ->softDelete($record->id, (string) $data['deletion_reason']);
                    }),
                Action::make('restore')
                    ->label('Restore')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (FeatureRequest $record): bool => $record->trashed())
                    ->action(function (FeatureRequest $record): void {
                        resolve(FeatureRequestServiceContract::class)->restore($record->id);
                    }),
            ])
            ->toolbarActions([
                // Deliberately no soft-delete bulk action — bulk delete
                // would skip the required deletion_reason and poison the
                // audit trail. Force-delete + restore are safe because
                // they don't create new "why" entries.
                BulkActionGroup::make([
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
