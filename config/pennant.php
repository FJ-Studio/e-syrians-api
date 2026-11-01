<?php

declare(strict_types=1);

return [
    // Admin-managed site-wide flags must survive subsequent requests / workers.
    'default' => 'database',

    'stores' => [
        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'features',
        ],
    ],
];
