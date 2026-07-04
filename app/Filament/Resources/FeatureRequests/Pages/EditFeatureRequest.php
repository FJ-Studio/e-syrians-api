<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests\Pages;

use Filament\Actions\Action;
use App\Models\FeatureRequest;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\ForceDeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;
use App\Contracts\FeatureRequestServiceContract;
use App\Filament\Resources\FeatureRequests\FeatureRequestResource;

class EditFeatureRequest extends EditRecord
{
    protected static string $resource = FeatureRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            // Custom soft-delete that requires a reason and routes
            // through FeatureRequestService — matches the row-level
            // action in FeatureRequestsTable so both entry points
            // enforce the same audit rule.
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
                    $this->redirect(FeatureRequestResource::getUrl('index'));
                }),
            ForceDeleteAction::make(),
            Action::make('restore')
                ->label('Restore')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (FeatureRequest $record): bool => $record->trashed())
                ->action(function (FeatureRequest $record): void {
                    resolve(FeatureRequestServiceContract::class)->restore($record->id);
                }),
        ];
    }
}
