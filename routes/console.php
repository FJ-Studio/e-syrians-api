<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:stats-daily-users')->dailyAt('23:58');
