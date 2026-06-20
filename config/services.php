<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'web_client_id' => env('APPLE_WEB_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    /*
    | reCAPTCHA Enterprise — verified via Google Cloud's Assessments API,
    | not the legacy `siteverify` endpoint. The frontend (mobile + web)
    | uses `grecaptcha.enterprise.execute(...)` which produces tokens that
    | `siteverify` rejects with `browser-error`. The middleware calls:
    |
    |   POST https://recaptchaenterprise.googleapis.com/v1/projects/
    |        {project_id}/assessments?key={api_key}
    |
    | Required env vars. Get them from GCP Console:
    |   - RECAPTCHA_PROJECT_ID  → Project picker top-bar → "Project ID"
    |   - RECAPTCHA_API_KEY     → APIs & Services → Credentials → API key
    |                             with reCAPTCHA Enterprise API enabled
    |   - RECAPTCHA_SITE_KEY    → Security → reCAPTCHA Enterprise → your
    |                             key (same value as mobile's
    |                             EXPO_PUBLIC_RECAPTCHA_SITE_KEY)
    |   - RECAPTCHA_MIN_SCORE   → optional, defaults to 0.7
    |
    | The legacy `RECAPTCHA_SECRET` field has been retired — it's
    | meaningless for Enterprise tokens and was the source of the
    | `browser-error` we hit during migration.
    */
    'recaptcha' => [
        'project_id' => env('RECAPTCHA_PROJECT_ID'),
        'api_key' => env('RECAPTCHA_API_KEY'),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.7),
    ],

    'internal_api_key' => env('INTERNAL_API_KEY'),

    'bigquery' => [
        'enabled' => env('BIGQUERY_ENABLED', false),
        'project_id' => env('BIGQUERY_PROJECT_ID'),
        'dataset' => env('BIGQUERY_DATASET', 'e_syrians_audit'),
        'credentials' => env('BIGQUERY_CREDENTIALS'),
        'tables' => [
            'profile_changes' => 'profile_changes',
            'poll_votes' => 'poll_votes',
            'poll_audience_rules' => 'poll_audience_rules',
        ],
    ],

];
