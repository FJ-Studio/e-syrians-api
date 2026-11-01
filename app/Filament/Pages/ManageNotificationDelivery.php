<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use UnitEnum;
use BackedEnum;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use App\Enums\NotificationEvent;
use App\Enums\NotificationCategory;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Fieldset;
use App\Services\NotificationDeliverySettingsService;

/** @property Schema $form */
class ManageNotificationDelivery extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Notification delivery';

    protected static ?string $title = 'Notification delivery';

    protected string $view = 'filament.pages.manage-notification-delivery';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole('admin');
    }

    public function mount(NotificationDeliverySettingsService $settings): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['events' => $settings->matrix()]);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (NotificationCategory::cases() as $category) {
            $events = [];
            foreach (NotificationEvent::cases() as $event) {
                if ($event->category() !== $category) {
                    continue;
                }
                $events[] = Fieldset::make($event->label())
                    ->schema([
                        Toggle::make('events.'.$event->value.'.in_app')
                            ->label('In app')->rules(['required', 'boolean']),
                        Toggle::make('events.'.$event->value.'.push')
                            ->label('Push')->rules(['required', 'boolean']),
                    ])->columns(2);
            }
            $sections[] = Section::make($category->label())->schema($events);
        }

        return $schema->statePath('data')->components($sections);
    }

    public function save(NotificationDeliverySettingsService $settings): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $settings->save($data['events']);

        Notification::make()->title('Notification delivery settings saved')->success()->send();
    }
}
