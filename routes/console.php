<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:daily-statistics')->dailyAt('23:58');
Schedule::command('app:send-weekly-newsletter')->weeklyOn(6, '09:00'); // Saturday at 9 AM
Schedule::command('model:prune', ['--model' => 'App\\Models\\ProfileUpdate'])->daily();
