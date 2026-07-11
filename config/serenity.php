<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Serenity Logger Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for local debugging logs that are stored in JSON format.
    | This is useful for developers to debug issues without Sentry.
    |
    */
    
    'enabled' => env('SERENITY_ENABLED', true),
    
    'log_file' => storage_path('logs/serenity.json'),
    'max_logs' => env('SERENITY_MAX_LOGS', 1000),
    
    'excluded_exceptions' => [
        Illuminate\Validation\ValidationException::class,
        Illuminate\Auth\AuthenticationException::class,
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    ],
    
    'levels' => [
        'emergency',
        'alert',
        'critical',
        'error',
        'warning',
        'notice',
        'info',
        'debug',
    ],
];