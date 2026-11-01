<?php

declare(strict_types=1);

return [
    // One-time upgrade defaults only. Pennant persists each value on first resolution;
    // subsequent runtime decisions and Filament changes use the database, not these env vars.
    'initial_states' => [
        'notification-events' => env('NOTIFICATION_EVENTS_ENABLED', true),
        'expo-push' => env('EXPO_PUSH_ENABLED', false),
        'bigquery-export' => env('BIGQUERY_ENABLED', false),
    ],
];
