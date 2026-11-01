<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use UnitEnum;
use BackedEnum;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use App\Services\DonationSettingsService;
use Filament\Schemas\Components\Utilities\Get;

/**
 * @property Schema $form
 */
class ManageDonations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Donations';

    protected static ?string $title = 'Donation settings';

    protected string $view = 'filament.pages.manage-donations';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole('admin');
    }

    public function mount(DonationSettingsService $settings): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'crypto_enabled' => $settings->cryptoEnabled(),
            'wallets' => $settings->wallets(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Cryptocurrency donations')
                    ->description('Controls the crypto section on both donation pages. Card payments are unaffected.')
                    ->schema([
                        Toggle::make('crypto_enabled')
                            ->label('Enable cryptocurrency donations')
                            ->helperText('Global Laravel Pennant flag: crypto-donations. Disabled by default.')
                            ->live(),
                        Repeater::make('wallets')
                            ->label('Donation wallets')
                            ->helperText('Only enter public receiving addresses, never private keys or recovery phrases. Verify the currency and network before enabling donations.')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Currency name')
                                    ->placeholder('Bitcoin')
                                    ->required()
                                    ->maxLength(80),
                                TextInput::make('currency')
                                    ->label('Currency symbol')
                                    ->placeholder('BTC')
                                    ->required()
                                    ->maxLength(20)
                                    ->regex('/\A[A-Za-z0-9]+\z/')
                                    ->dehydrateStateUsing(fn (string $state): string => Str::upper($state)),
                                TextInput::make('network')
                                    ->placeholder('e.g. ERC-20 or TRC-20')
                                    ->maxLength(60),
                                TextInput::make('address')
                                    ->label('Public wallet address')
                                    ->required()
                                    ->maxLength(255)
                                    ->regex('/\A[A-Za-z0-9:._-]+\z/')
                                    ->extraInputAttributes(['dir' => 'ltr'])
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->minItems(fn (Get $get): int => $get('crypto_enabled') ? 1 : 0)
                            ->maxItems(20)
                            ->addActionLabel('Add currency / network')
                            ->reorderable()
                            ->itemLabel(fn (array $state): string => $state['name'] ?? 'New wallet'),
                    ]),
            ]);
    }

    public function save(DonationSettingsService $settings): void
    {
        // Authorize Livewire mutations too, not just the initial page visit.
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $settings->save((bool) $data['crypto_enabled'], array_values($data['wallets']));

        Notification::make()->title('Donation settings saved')->success()->send();
    }
}
