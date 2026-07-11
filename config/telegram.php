<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Telegram bot credentials here. You can get a bot token
    | from @BotFather on Telegram.
    |
    */
    
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_CHAT_ID'),
    
    'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org/bot'),
    
    'timeout' => env('TELEGRAM_TIMEOUT', 30),
    'retry_times' => env('TELEGRAM_RETRY_TIMES', 3),
    
    // Error notification settings
    'error_notification' => [
        'enabled' => env('TELEGRAM_ERROR_NOTIFICATION_ENABLED', false),
        'min_level' => env('TELEGRAM_ERROR_MIN_LEVEL', 'error'), // debug, info, warning, error, critical
        'include_stack_trace' => env('TELEGRAM_INCLUDE_STACK_TRACE', true),
        'max_message_length' => env('TELEGRAM_MAX_MESSAGE_LENGTH', 4096),
    ],
    
    // Daily report settings
    'daily_report' => [
        'enabled' => env('TELEGRAM_DAILY_REPORT_ENABLED', true),
        'time' => env('TELEGRAM_DAILY_REPORT_TIME', '20:00'),
    ],
];