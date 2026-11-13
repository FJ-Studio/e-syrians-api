<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('IDENTITY_SANDBOX_ENABLED', false),
    'test_users' => array_values(array_filter(array_map('trim', explode(',', (string) env('IDENTITY_SANDBOX_TEST_USERS', ''))))),
    'api_key' => env('DIDIT_SANDBOX_API_KEY'),
    'workflow_id' => env('DIDIT_SANDBOX_WORKFLOW_ID'),
    'scenario' => env('DIDIT_SANDBOX_SCENARIO', 'approve'),
];
