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
    'store' => [
        'ios' => [
            'enabled' => env('IDENTITY_STORE_IOS_ENABLED', false),
            'bundle' => 'com.esyrians.app',
            'product' => env('IDENTITY_STORE_IOS_PRODUCT_ID', 'identity_verification_credit'),
            'issuer' => env('IDENTITY_STORE_APPLE_ISSUER_ID'),
            'key_id' => env('IDENTITY_STORE_APPLE_KEY_ID'),
            'key_path' => env('IDENTITY_STORE_APPLE_KEY_PATH'),
        ],
        'android' => [
            'enabled' => env('IDENTITY_STORE_ANDROID_ENABLED', false),
            'bundle' => 'com.esyrians.app',
            'product' => env('IDENTITY_STORE_ANDROID_PRODUCT_ID', 'identity_verification_credit'),
            'key_path' => env('IDENTITY_STORE_GOOGLE_CREDENTIALS_PATH'),
        ],
    ],
];
