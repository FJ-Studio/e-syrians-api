<?php

return [
    // Keep vendor defaults plus the incoming Expo registration token out of telemetry.
    'redact_payload_fields' => array_values(array_unique([
        ...explode(',', env('NIGHTWATCH_REDACT_PAYLOAD_FIELDS', '_token,password,password_confirmation')),
        'expo_push_token',
    ])),
];
