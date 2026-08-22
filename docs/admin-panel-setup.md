# Filament Admin Panel — setup guide

Runs at `admin.e-syrians.com` (separate subdomain, same Laravel app).
English-only UI. Auth via session cookie (`web` guard), NOT the same
bearer-token flow the mobile/web use.

---

## Step 1 — Install

```bash
composer require filament/filament:"^3.2"
php artisan filament:install --panels
```

The installer will prompt for a panel id — use `admin`. This creates
`app/Providers/Filament/AdminPanelProvider.php`.

Register the provider in `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
];
```

Publish assets on deploy — add to your deployment pipeline:

```bash
php artisan filament:assets
```

---

## Step 2 — Subdomain routing

Replace the scaffolded `AdminPanelProvider::panel()` body with the
config below. Key changes vs the default:

- `->domain('admin.e-syrians.com')` binds this panel to the subdomain
  so it doesn't conflict with the API's routes.
- `->path('')` mounts it at the root of that subdomain (so
  `admin.e-syrians.com` → login, not `admin.e-syrians.com/admin`).
- `->id('admin')` — used internally and in URLs.

```php
// app/Providers/Filament/AdminPanelProvider.php
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('')                                   // root of subdomain
        ->domain('admin.e-syrians.com')              // subdomain binding
        ->login()
        ->colors([
            'primary' => Color::hex('#393D98'),      // e-syrians brand
        ])
        ->discoverResources(
            in: app_path('Filament/Resources'),
            for: 'App\\Filament\\Resources',
        )
        ->discoverPages(
            in: app_path('Filament/Pages'),
            for: 'App\\Filament\\Pages',
        )
        ->pages([
            Pages\Dashboard::class,
        ])
        ->discoverWidgets(
            in: app_path('Filament/Widgets'),
            for: 'App\\Filament\\Widgets',
        )
        ->widgets([
            // will populate with StatsService widgets in Step 5
        ])
        ->middleware([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
        ])
        ->authMiddleware([
            Authenticate::class,
        ])
        // Gate the whole panel to users with the `admin` role.
        // canAccessPanel() lives on User (see step 3).
        ->tenantMiddleware([], isPersistent: false);
}
```

### Nginx / Caddy config

Point `admin.e-syrians.com` at the same Laravel app as `api.e-syrians.com`.
The `->domain()` call above is what routes the traffic once it lands.

---

## Step 3 — Gate access to admins only

Add to `app/Models/User.php`:

```php
use Filament\Panel;
use Filament\Models\Contracts\FilamentUser;

class User extends Authenticatable implements MustVerifyEmail, FilamentUser
{
    // …existing code…

    /**
     * Called by Filament on every request. Only users with the
     * `admin` Spatie role can log into the panel. Everyone else
     * gets 403 even if they know an admin email.
     *
     * The `admin` role is seeded by RolesPermissionsSeeder — assign
     * yourself with:
     *   User::find(1)->assignRole('admin');
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole('admin');
    }
}
```

---

## Step 4 — First Resource: Users

Create `app/Filament/Resources/UserResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'People';

    protected static ?int $navigationSort = 1;

    // Route model binding uses uuid on the mobile/API side; keep
    // Filament on the incrementing id for admin-URL stability.
    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identity')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->required(),
                    Forms\Components\TextInput::make('surname')->required(),
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('uuid')
                        ->disabled()
                        ->dehydrated(false),
                ]),

            Forms\Components\Section::make('Verification')
                ->columns(2)
                ->schema([
                    Forms\Components\DateTimePicker::make('verified_at')
                        ->helperText('Set to a date to manually verify. Clear to un-verify.'),
                    Forms\Components\Select::make('verification_reason')
                        ->options([
                            'first_registrant' => 'First registrant',
                            'peer_verification' => 'Peer verification',
                            'manual_admin' => 'Manual — admin override',
                        ])
                        ->helperText('Prefer `manual_admin` when set from here.'),
                ]),

            Forms\Components\Section::make('Roles')
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->options(Role::pluck('name', 'name')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->formatStateUsing(fn ($record) => "{$record->name} {$record->surname}"),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\IconColumn::make('verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->verified_at !== null),
                Tables\Columns\TextColumn::make('roles.name')
                    ->badge()
                    ->separator(','),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->label('Deleted')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('verified')
                    ->label('Verification')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('verified_at'),
                        false: fn (Builder $q) => $q->whereNull('verified_at'),
                    ),
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn ($record) => $record->verified_at === null)
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update([
                            'verified_at' => now(),
                            'verification_reason' => 'manual_admin',
                        ]);
                    }),
                Tables\Actions\Action::make('unverify')
                    ->label('Un-verify')
                    ->icon('heroicon-o-x-mark')
                    ->color('warning')
                    ->visible(fn ($record) => $record->verified_at !== null)
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->update(['verified_at' => null])),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        // Include soft-deleted rows in admin — the moderation view
        // needs to see banned users so admins can restore them.
        return parent::getEloquentQuery()->withoutGlobalScopes()
            ->with('roles');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
```

And the four page classes Filament expects (auto-generated by
`php artisan make:filament-resource User --view --generate`):

```
app/Filament/Resources/UserResource/Pages/CreateUser.php
app/Filament/Resources/UserResource/Pages/EditUser.php
app/Filament/Resources/UserResource/Pages/ListUsers.php
app/Filament/Resources/UserResource/Pages/ViewUser.php
```

Those are one-liners; the artisan command scaffolds them for you.

---

## Step 5 — Next resources to build (rough priority order)

Following the same pattern (`php artisan make:filament-resource X --view --generate` then customize):

1. **FeatureRequestResource** — moderate feature requests, transition
   status (idea → in_development → in_testing → shipped). Custom
   action per status.
2. **PollResource** — moderate polls, close/reopen, view voter list,
   soft-delete offensive content.
3. **UserVerificationResource** — read-only audit of who verified
   whom. Filters by verifier, target, status (active vs cancelled).
4. **SuspiciousActivityResource** — read-only view of the webhook
   feed. Action: mark reviewed.
5. **ProfileUpdateResource** — read-only audit trail for GDPR /
   support queries ("what did this user change on X date").
6. **DeviceResource** — read-only view of registered OneSignal
   devices. Filter by user. Action: manually revoke.

---

## Step 6 — Stats dashboard widgets

Reuse `App\Contracts\StatsServiceContract`:

```php
// app/Filament/Widgets/PlatformStatsOverview.php
namespace App\Filament\Widgets;

use App\Contracts\StatsServiceContract;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStatsOverview extends BaseWidget
{
    public function __construct(
        private readonly StatsServiceContract $stats,
    ) {}

    protected function getStats(): array
    {
        $s = $this->stats->getPlatformStats();

        return [
            Stat::make('Total registered', number_format($s['registered'])),
            Stat::make('Verified users', number_format($s['verified'])),
            Stat::make("Today's registrations", number_format($s['today'])),
        ];
    }
}
```

Register the widget in `AdminPanelProvider::widgets([...])`.

---

## Step 7 — Assign yourself the admin role

One-time. On production:

```bash
php artisan tinker
> User::where('email', 'fjobeir@gmail.com')->first()->assignRole('admin');
```

Log in at `https://admin.e-syrians.com` with your normal account
credentials.

---

## Time estimate

- Steps 1-4 (install + subdomain + gate + User resource): **half a day**
- Step 5 (5 more Resources): **2-3 days**
- Step 6 (widgets): **half a day**
- Testing + polish: **half a day**

**Total: ~1 week of focused work** for a solid v1 admin panel.
