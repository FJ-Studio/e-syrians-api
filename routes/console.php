<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\HardDeleteExpiredAccountsJob;

Schedule::command('app:daily-statistics')->dailyAt('23:58');
Schedule::command('app:send-weekly-newsletter')->weeklyOn(6, '09:00'); // Saturday at 9 AM
Schedule::command('model:prune', ['--model' => 'App\\Models\\ProfileUpdate'])->daily();

// Sweep every user whose 15-day deletion grace period has expired and
// hard-delete their account. Runs at 03:00 UTC — quiet hours for the
// user base and well after the daily-statistics job's 23:58 slot so
// the two never contend for the DB.
Schedule::job(new HardDeleteExpiredAccountsJob())->dailyAt('03:00');
