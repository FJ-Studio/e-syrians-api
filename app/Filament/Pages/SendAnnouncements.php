<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use UnitEnum;
use BackedEnum;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Textarea;
use App\Models\NotificationAnnouncement;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use App\Jobs\FanOutPlatformNotificationJob;

/** @property Schema $form */
class SendAnnouncements extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';
    protected static string|UnitEnum|null $navigationGroup = 'Settings';
    protected static ?string $title = 'Send announcement';
    protected string $view = 'filament.pages.send-announcements';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && auth()->user()->hasRole('admin');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('title')->required()->maxLength(120),
            Textarea::make('body')->required()->maxLength(2000)->rows(5)
                ->helperText('Shown in the inbox. Push previews use generic text. Only users who opted into announcements are included.'),
        ]);
    }

    public function sendAction(): Action
    {
        return Action::make('send')->label('Queue announcement')->requiresConfirmation()
            ->modalDescription('Send this announcement to opted-in accounts? This cannot be recalled.')
            ->action(function (): void {
                abort_unless(static::canAccess(), 403);
                $data = $this->form->getState();
                $announcement = NotificationAnnouncement::create([
                    'uuid' => (string) Str::uuid(), 'created_by' => auth()->id(),
                    'title' => $data['title'], 'body' => $data['body'],
                ]);
                dispatch(new FanOutPlatformNotificationJob('announcement', $announcement->id))->afterCommit();
                $this->form->fill();
                Notification::make()->title('Announcement queued (not a delivery confirmation)')->success()->send();
            });
    }
}
