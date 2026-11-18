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
            'sandbox_enabled' => env('IDENTITY_STORE_IOS_SANDBOX_ENABLED', false),
            'bundle' => 'com.esyrians.app',
            'product' => env('IDENTITY_STORE_IOS_PRODUCT_ID', 'identity_verification_credit'),
            'issuer' => env('IDENTITY_STORE_APPLE_ISSUER_ID'),
            'key_id' => env('IDENTITY_STORE_APPLE_KEY_ID'),
            'private_key' => env('IDENTITY_STORE_APPLE_PRIVATE_KEY'),
            'key_path' => env('IDENTITY_STORE_APPLE_KEY_PATH'),
            'app_id' => env('IDENTITY_STORE_APPLE_APP_ID', 6776366211),
            'node_binary' => env('IDENTITY_STORE_APPLE_NODE_BINARY', 'node'),
            'root_certificates' => env('IDENTITY_STORE_APPLE_ROOT_CERTIFICATES')
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('IDENTITY_STORE_APPLE_ROOT_CERTIFICATES')))))
                : [
                    resource_path('certificates/apple/AppleIncRootCertificate.pem'),
                    resource_path('certificates/apple/AppleRootCA-G2.pem'),
                    resource_path('certificates/apple/AppleRootCA-G3.pem'),
                ],
        ],
        'android' => [
            'enabled' => env('IDENTITY_STORE_ANDROID_ENABLED', false),
            'bundle' => 'com.esyrians.app',
            'product' => env('IDENTITY_STORE_ANDROID_PRODUCT_ID', 'identity_verification_credit'),
            'credentials_json' => env('IDENTITY_STORE_GOOGLE_CREDENTIALS_JSON'),
            'key_path' => env('IDENTITY_STORE_GOOGLE_CREDENTIALS_PATH'),
        ],
    ],
];
