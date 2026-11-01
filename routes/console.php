<?php

use App\Models\Poll;
use Laravel\Pennant\Feature;
use App\Enums\PlatformFeature;
use App\Services\FeatureFlagService;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\HardDeleteExpiredAccountsJob;
use App\Jobs\FanOutPlatformNotificationJob;

Schedule::command('app:daily-statistics')->dailyAt('23:58');
Schedule::command('app:send-weekly-newsletter')->weeklyOn(6, '09:00'); // Saturday at 9 AM
Schedule::command('model:prune', ['--model' => 'App\\Models\\ProfileUpdate'])->daily();

// Sweep every user whose 15-day deletion grace period has expired and
// hard-delete their account. Runs at 03:00 UTC — quiet hours for the
// user base and well after the daily-statistics job's 23:58 slot so
// the two never contend for the DB.
Schedule::job(new HardDeleteExpiredAccountsJob())->dailyAt('03:00');

Schedule::call(function (): void {
    Feature::flushCache();
    if (! resolve(FeatureFlagService::class)->enabled(PlatformFeature::NotificationEvents)) {
        return;
    }
    Poll::query()->whereNull('invitations_dispatched_at')
        ->where('start_date', '<=', now())->where('end_date', '>', now())
        ->select('id')->chunkById(200, function ($polls): void {
            foreach ($polls as $poll) {
                dispatch(new FanOutPlatformNotificationJob('poll_opened', $poll->id));
            }
        });
})->name('dispatch-open-poll-invitations')->everyMinute()->withoutOverlapping();

Schedule::call(function (): void {
    Feature::flushCache();
    if (! resolve(FeatureFlagService::class)->enabled(PlatformFeature::NotificationEvents)) {
        return;
    }
    Poll::withoutGlobalScope('public_polls')->whereNull('closure_notified_at')->where('end_date', '<=', now())
        ->select('id')->chunkById(200, function ($polls): void {
            foreach ($polls as $poll) {
                dispatch(new FanOutPlatformNotificationJob('poll_closed', $poll->id));
            }
        });
})->name('dispatch-closed-poll-updates')->everyMinute()->withoutOverlapping();
