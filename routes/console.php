<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:stats-daily-users')->daily();
