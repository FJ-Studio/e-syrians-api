<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\Pages\Dashboard;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Admin panel.
 *
 *   - Production: `https://admin.e-syrians.com/` (subdomain-scoped)
 *   - Non-production (local Herd, staging, etc.):
 *     `<APP_URL>/admin` (path-scoped, e.g. `https://e-syrians-api.test/admin`)
 *
 * The split lives in `panel()` below and branches on
 * `app()->environment('production')` so no env var wiring is needed —
 * boot the API on Herd, browse to your dev host + `/admin`, and
 * you're in.
 *
 * Why a subdomain in production instead of `/admin` on the main host:
 *
 *   1. Cleanly separates the admin surface from the public API
 *      (`api.e-syrians.com`) at the DNS / CDN layer. Admin can have
 *      its own SSL cert rotation, its own rate-limit config, its own
 *      access-control (e.g. IP allowlist at Cloudflare) without
 *      changing anything on the API side.
 *
 *   2. Sanctum bearer-token API + Filament session cookies don't
 *      collide. Cookies are scoped to `admin.e-syrians.com` and never
 *      travel to `api.e-syrians.com`, so a compromised admin session
 *      can't be replayed against API endpoints and vice versa.
 *
 *   3. Route conflicts avoided. The API defines
 *      `POST /users/logout` etc.; if we mounted Filament at `/admin`
 *      on the same host, both would technically be on the same domain
 *      and would rely on path-prefix ordering. Different subdomain =
 *      no ambiguity.
 *
 * Locally the trade-offs of (1) and (2) don't matter (no CDN, dev
 * data), and (3) doesn't bite because the API currently defines no
 * routes under `/admin/*`. Point (3) is the one to watch: if we ever
 * add an `/admin` route to `routes/api.php`, the local path-mounted
 * panel will start swallowing it. Prod stays fine because it's on a
 * different subdomain.
 *
 * Access gate lives in `User::canAccessPanel()` (see
 * `App\Models\User`) — only users with the Spatie `admin` role can
 * log in. The seeder `RolesPermissionsSeeder` provisions the role;
 * assign yourself with `User::first()->assignRole('admin')`.
 *
 * English-only by design — admin users are internal and we're not
 * paying the Kurmanji translation cost for a screen that never faces
 * the public.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->login()
            ->colors([
                // e-syrians brand indigo (#393D98). Matches the mobile
                // + web palette so admins get a familiar hue rather
                // than Filament's default amber.
                'primary' => Color::hex('#393D98'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        // Mount location: subdomain in prod (see class-level doc for
        // the "why"), path on any other environment so local dev
        // works without touching DNS or /etc/hosts. `admin.<your-
        // herd-domain>` would need Herd Pro custom-domain wiring;
        // `<your-herd-domain>/admin` just works.
        if (app()->environment('production')) {
            return $panel
                ->path('')
                ->domain('admin.e-syrians.com');
        }

        return $panel->path('admin');
    }
}
