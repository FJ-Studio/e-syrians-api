<?php

namespace App\Listeners;

use App\Events\Registered;
use Illuminate\Support\Facades\Cache;

class UpdateStatistics
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        $cache_keys = config('e-syrians.cache', []);
        foreach ($cache_keys as $key => $value) {
            if ($value !== 'daily_registrants') {
                Cache::forget($value);

                continue;
            }
        }
    }
}
