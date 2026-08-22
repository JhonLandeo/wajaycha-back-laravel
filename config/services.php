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
    'openai' => [
        'api_key' => env('OPENAI_KEY'),
        'api_url' => env('OPENAI_API_URL'),
    ],
    'aws' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'telegram' => [
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'secret_token' => env('TELEGRAM_SECRET_TOKEN'),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    ],

    'whatsapp' => [
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'api_url' => env('WHATSAPP_API_URL'),
        'version' => env('WHATSAPP_API_VERSION'),
        'phone_id' => env('WHATSAPP_PHONE_ID'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'webhook_url' => env('WHATSAPP_WEBHOOK_URL'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    /*
     * Google Sign-In. The client ID is not a secret — the browser sends it to
     * Google in plain sight — but it is the audience every ID token is checked
     * against, so a wrong value here rejects every login rather than leaking
     * anything.
     *
     * It has to be read through config and never through `env()` at call time:
     * the deploy runs `php artisan config:cache`, and after that `env()` outside
     * of a config file returns null.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],
];
