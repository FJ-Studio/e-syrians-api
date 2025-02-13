<?php

return [
    'locales' => [
        'en' => 'English',
        'ar' => 'Arabic',
        'ku' => 'Kurdish',
    ],
    'verification' => [
        // The number of registrants required to verify a profile
        'min' => 3,
        // The difference between verifiers and number of verifications.
        // Ex: A user has 3 verifiers can verify 2 profile(s).
        'diff' => 1,
        // The number of verifications can be made by a user
        'max' => 25,
        // Number of allowed data updates before losing verifications
        'basic_data_updates_limit' => 2,
        'social_links_updates_limit' => 5,
        'country_updates_limit' => 2,
    ],
    'cache' => [
        'daily_registrants' => 'daily_registrants',
        'gender' => 'gender',
        'age' => 'age',
        'hometown' => 'hometown',
        'country' => 'country',
        'ethnicity' => 'ethnicity',
        'religion' => 'religion',
    ],
    ''
];
