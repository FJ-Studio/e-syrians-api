<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\AuthService;
use App\Services\PollService;
use App\Services\StatsService;
use App\Services\ProfileService;
use App\Services\PasswordService;
use App\Services\UserPollService;
use App\Services\FileUploadService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Date;
use App\Services\VerificationService;
use Illuminate\Support\Facades\Event;
use App\Contracts\AuthServiceContract;
use App\Contracts\PollServiceContract;
use Illuminate\Support\Facades\Config;
use App\Contracts\StatsServiceContract;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Google\Provider;
use App\Contracts\ProfileServiceContract;
use App\Contracts\PasswordServiceContract;
use App\Contracts\UserPollServiceContract;
use App\Contracts\FileUploadServiceContract;
use App\Contracts\VerificationServiceContract;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     *
     * @var array<string, string>
     */
    public array $bindings = [
        AuthServiceContract::class => AuthService::class,
        FileUploadServiceContract::class => FileUploadService::class,
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
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('google', Provider::class);
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
