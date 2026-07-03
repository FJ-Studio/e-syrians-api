<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Poll;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PollService;
use App\Services\StatsService;
use App\Services\DeviceService;
use App\Services\ProfileService;
use App\Services\PasswordService;
use App\Services\UserPollService;
use App\Services\OneSignalService;
use App\Services\FileUploadService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Date;
use App\Services\VerificationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use App\Contracts\AuthServiceContract;
use App\Contracts\PollServiceContract;
use Illuminate\Support\Facades\Config;
use App\Contracts\StatsServiceContract;
use App\Services\FeatureRequestService;
use Illuminate\Support\ServiceProvider;
use App\Contracts\DeviceServiceContract;
use App\Contracts\ProfileServiceContract;
use App\Contracts\PasswordServiceContract;
use App\Contracts\UserPollServiceContract;
use App\Contracts\OneSignalServiceContract;
use App\Contracts\FileUploadServiceContract;
use App\Contracts\VerificationServiceContract;
use Illuminate\Auth\Notifications\VerifyEmail;
use App\Contracts\FeatureRequestServiceContract;
use Illuminate\Auth\Notifications\ResetPassword;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Apple\Provider as AppleProvider;
use SocialiteProviders\Google\Provider as GoogleProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     *
     * @var array<string, string>
     */
    public array $bindings = [
        AuthServiceContract::class => AuthService::class,
        DeviceServiceContract::class => DeviceService::class,
        FeatureRequestServiceContract::class => FeatureRequestService::class,
        FileUploadServiceContract::class => FileUploadService::class,
        OneSignalServiceContract::class => OneSignalService::class,
        PasswordServiceContract::class => PasswordService::class,
        PollServiceContract::class => PollService::class,
        ProfileServiceContract::class => ProfileService::class,
        StatsServiceContract::class => StatsService::class,
        UserPollServiceContract::class => UserPollService::class,
        VerificationServiceContract::class => VerificationService::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS on every URL Laravel generates (`url()`,
        // `route()`, `asset()`, `URL::to()`, etc.).
        //
        // Every host this Laravel app is served from — api.e-syrians.com,
        // admin.e-syrians.com locally under Herd, and their production
        // counterparts — is behind TLS. TLS termination happens at the
        // proxy layer (Herd's local proxy, Cloudflare in production),
        // and the request forwarded to PHP arrives as plain HTTP with
        // an `X-Forwarded-Proto: https` header.
        //
        // `trustProxies(at: '*')` in `bootstrap/app.php` is the
        // "polite" way to handle this — but Herd's forwarded-header
        // shape doesn't always line up with Laravel's expectations,
        // and Filament (which uses `asset()` to link its CSS/JS)
        // ends up emitting `http://admin.e-syrians.com/css/…` URLs
        // while the browser loaded the page over `https://`, blocking
        // every asset as Mixed Content.
        //
        // Belt-and-suspenders: force the scheme unconditionally.
        // Safe because we're never actually served over plain HTTP
        // anywhere the URL generator runs — production strictly HTTPS,
        // local Herd strictly HTTPS. If we ever add a non-HTTPS
        // deployment (unlikely — insecure and modern browsers ignore
        // cookies over HTTP), remove this line and rely on the
        // trusted-proxy path instead.
        URL::forceScheme('https');

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('google', GoogleProvider::class);
            $event->extendSocialite('apple', AppleProvider::class);
        });

        // Custom route binding for `{poll}`. The Poll model has a
        // `public_polls` global scope that hides private polls
        // (`is_private = true`). That's the right default for the
        // public show endpoint, but it breaks owner-only routes
        // (edit, status toggle) for private polls — Laravel's
        // implicit binding runs Poll::find() which inherits the
        // scope and 404s the owner.
        //
        // This binding resolves WITHOUT the global scope and then
        // enforces the privacy rule explicitly: anyone can resolve
        // public polls; only the creator can resolve their private
        // ones. Non-creators on private polls still get 404, so
        // the previous public-show behaviour is preserved.
        Route::bind('poll', function (string $value) {
            /** @var Poll|null $poll */
            $poll = Poll::withoutGlobalScope('public_polls')->find($value);
            if (! $poll) {
                abort(404);
            }
            if ($poll->is_private && $poll->created_by !== auth('sanctum')->id()) {
                abort(404);
            }
            return $poll;
        });

        ResetPassword::createUrlUsing(function (User $user, string $token) {
            return env('FRONTEND_URL').'/auth/reset-password?token='.$token;
        });

        VerifyEmail::createUrlUsing(function ($notifiable) {
            $frontendUrl = env('FRONTEND_URL');
            $verifyUrl = URL::temporarySignedRoute(
                'verification.verify',
                Date::now()->addMinutes(Config::get('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                    'lang' => $notifiable->language ?? 'en',
                ],
                false
            );

            return $frontendUrl.$verifyUrl;
        });
    }
}
