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

    'social' => [
        'providers' => [
            'google' => ['userinfo_url' => env('GOOGLE_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'), 'id_field' => 'sub', 'email_field' => 'email'],
            'facebook' => ['userinfo_url' => env('FACEBOOK_USERINFO_URL', 'https://graph.facebook.com/me?fields=id,name,email'), 'id_field' => 'id', 'email_field' => 'email'],
            'apple' => ['userinfo_url' => env('APPLE_USERINFO_URL'), 'id_field' => 'sub', 'email_field' => 'email'],
        ],
    ],

];
