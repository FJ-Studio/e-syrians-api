<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use UnitEnum;
use BackedEnum;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use App\Enums\PlatformFeature;
use Livewire\Attributes\Locked;
use App\Services\FeatureFlagService;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/** @property Schema $form */
class ManageFeatureFlags extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';
    protected static string|UnitEnum|null $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Feature flags';
    protected static ?string $title = 'Feature flags';
    protected string $view = 'filament.pages.manage-feature-flags';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, bool> */
    #[Locked]
    public array $initialStates = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user instanceof User && $user->hasRole('admin');
    }

    public function mount(FeatureFlagService $flags): void
    {
        abort_unless(static::canAccess(), 403);
        $this->initialStates = $flags->states();
        $this->form->fill(['flags' => $this->initialStates]);
    }

    public function form(Schema $schema): Schema
    {
        $flags = resolve(FeatureFlagService::class);
        $toggles = [];
        foreach (PlatformFeature::cases() as $feature) {
            $reason = $flags->unavailableReason($feature);
            $toggles[] = Toggle::make('flags.'.$feature->value)
                ->label($feature->label())
                ->helperText($feature->description().($reason ? ' Unavailable: '.$reason : ''))
                ->rules(['required', 'boolean'])
                ->live()
                // An unavailable integration can always be disabled, never newly enabled.
                ->disabled(fn (Get $get): bool => $reason !== null && ! $get('flags.'.$feature->value))
                ->dehydrated();
        }
        return $schema->statePath('data')->components([
            Section::make('Platform-wide features')->schema($toggles),
        ]);
    }

    public function saveAction(): Action
    {
        return Action::make('save')->label('Save feature flags')->requiresConfirmation()
            ->modalHeading('Apply platform-wide feature flags?')
            ->modalDescription('Enabling push can deliver queued alerts. Enabling BigQuery can export personal audit data. Review integration setup and user consent before enabling either. Credentials and security protections are not changed.')
            ->action(fn (FeatureFlagService $flags) => $this->save($flags));
    }

    public function save(FeatureFlagService $flags): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $flags->save($data['flags'], $this->initialStates);
        $this->initialStates = $flags->states();
        Notification::make()->title('Feature flags saved')->success()->send();
    }
}
