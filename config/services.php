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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'env' => env('PAYMONGO_ENV', 'sandbox'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'hofros_mobile' => [
        'app_key' => env('HOFROS_MOBILE_APP_KEY'),
    ],

    'ticketoc' => [
        'base_url' => env('TICKETOC_API_BASE_URL', 'http://127.0.0.1:8000/api/v1'),
    ],

    'airbnb' => [
        'base_url' => env('AIRBNB_API_BASE_URL', 'https://www.airbnb.com'),
    ],

];
