<?php


declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use App\Services\IdentityVerificationService;
use App\Filament\Resources\Users\UserResource;
use App\Contracts\IdentitySandboxServiceContract;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('grantVerificationCredit')
                ->label('Grant sandbox verification credit')
                ->modalDescription('Adds one credit for this test account. It does not charge the user or mark them as verified.')
                ->schema([
                    Textarea::make('reason')->required()->maxLength(240)
                        ->helperText('Internal audit note. Do not include identity documents or personal details.'),
                ])
                ->visible(function (User $record): bool {
                    $admin = Filament::auth()->user();

                    return $admin instanceof User && app(IdentitySandboxServiceContract::class)->canGrant($admin, $record);
                })
                ->action(function (User $record, array $data): void {
                    $admin = Filament::auth()->user();
                    abort_unless($admin instanceof User, 403);
                    app(IdentitySandboxServiceContract::class)->grant($admin, $record, $data['reason']);

                    Notification::make()->success()->title('Sandbox verification credit granted')->send();
                }),
            Action::make('grantLiveVerificationCredit')
                ->label('Grant live verification credit')
                ->modalDescription('Adds one non-transferable credit. The user pays nothing; Didit may charge E-Syrians when the credit is used.')
                ->schema([
                    Textarea::make('reason')->required()->maxLength(240)
                        ->helperText('Internal audit note. Do not include personal details or identity documents.'),
                ])
                ->visible(function (User $record): bool {
                    $admin = Filament::auth()->user();

                    return $admin instanceof User && app(IdentityVerificationService::class)->canGrant($admin, $record);
                })
                ->action(function (User $record, array $data): void {
                    $admin = Filament::auth()->user();
                    abort_unless($admin instanceof User, 403);
                    app(IdentityVerificationService::class)->grant($admin, $record, $data['reason']);

                    Notification::make()->success()->title('Live verification credit granted')->send();
                }),
        ];
    }
}
