<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    | Election Shield USSD service. It pushes events to /api/ussd-events
    | (Bearer webhook_token + HMAC signature with webhook_secret) and serves
    | the read API at api_url (Bearer api_token). Server side only: the API
    | token exposes every agent's phone number.
    */
    'ussd' => [
        'webhook_token' => env('USSD_WEBHOOK_TOKEN'),
        'webhook_secret' => env('USSD_WEBHOOK_SECRET'),
        'api_url' => rtrim((string) env('USSD_API_URL', 'https://ussd.techatronagency.com/api'), '/'),
        'api_token' => env('USSD_API_TOKEN'),
        'timeout' => (int) env('USSD_API_TIMEOUT', 20),
    ],

];
