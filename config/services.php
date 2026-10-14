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

    // Cloudflare Turnstile (the "I am human" check on client registration). Leave the secret empty locally: the check
    // is then skipped. Cloudflare publishes test keys: https://developers.cloudflare.com/turnstile/troubleshooting/testing/
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET'),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    // "Continue with Google" for clients. Create an OAuth client id (type: Web application) in Google Cloud Console >
    // APIs & Services > Credentials, and add the client panel origin (http://localhost:3002) under "Authorized JavaScript
    // origins". The same id goes in the client panel as NEXT_PUBLIC_GOOGLE_CLIENT_ID. Empty = Google sign-in is off.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'tokeninfo_url' => 'https://oauth2.googleapis.com/tokeninfo',
    ],

    // Domain registrar. Key: Dynadot > Tools > API (Production key); the server's IP must be allowed on that page.
    'dynadot' => [
        'key' => env('DYNADOT_API_KEY'),
        'url' => 'https://api.dynadot.com/api3.json',
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
