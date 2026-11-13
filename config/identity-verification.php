<?php

declare(strict_types=1);

return [
    'enabled' => env('IDENTITY_VERIFICATION_ENABLED', false),
    'didit' => [
        'key' => env('DIDIT_LIVE_API_KEY'),
        'workflow' => env('DIDIT_LIVE_WORKFLOW_ID'),
    ],
    'stripe' => [
        'enabled' => env('IDENTITY_STRIPE_CHECKOUT_ENABLED', false),
        'key' => env('IDENTITY_STRIPE_SECRET_KEY'),
        'price_id' => env('IDENTITY_STRIPE_PRICE_ID'),
        'webhook_secret' => env('IDENTITY_STRIPE_WEBHOOK_SECRET'),
    ],
];
