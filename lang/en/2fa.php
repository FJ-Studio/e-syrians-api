<?php

declare(strict_types=1);

return [
    'setup' => [
        'error' => [
            'already_enabled' => 'Two-factor authentication is already enabled.',
        ],
    ],
    'confirm' => [
        'error' => [
            'already_enabled' => 'Two-factor authentication is already enabled.',
            'not_setup' => 'Two-factor authentication has not been set up yet.',
            'invalid_code' => 'Invalid verification code.',
        ],
    ],
    'disable' => [
        'error' => [
            'not_enabled' => 'Two-factor authentication is not enabled.',
        ],
    ],
    'verify' => [
        'error' => [
            'invalid_challenge' => 'Invalid or expired two-factor challenge.',
            'user_not_found' => 'User not found for this challenge.',
            'invalid_code' => 'Invalid verification code.',
        ],
    ],
];
