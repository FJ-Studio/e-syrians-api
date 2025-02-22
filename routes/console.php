<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:daily-statistics')->dailyAt('23:58');
