<?php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'breadcrumbs' => [
        'logs' => true,
        'cache' => true,
        'sql_queries' => true,
        'sql_bindings' => true,
        'queue_jobs' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),

    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),

    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),
];