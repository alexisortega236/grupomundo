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

    'avm' => [
        'url' => env('AVM_SERVICE_URL', 'http://127.0.0.1:8003'),
        'token' => env('AVM_SERVICE_TOKEN'),
        'timeout' => env('AVM_SERVICE_TIMEOUT', 20),
    ],

    'avm_v2' => [
        'enabled' => env('AVM_V2_ENABLED', false),
        'shadow_mode' => env('AVM_V2_SHADOW_MODE', true),
        'public_result' => env('AVM_V2_PUBLIC_RESULT', false),
        'url' => env('AVM_SERVICE_URL', 'http://127.0.0.1:8003'),
        'token' => env('AVM_SERVICE_TOKEN'),
        'timeout' => env('AVM_V2_TIMEOUT', env('AVM_SERVICE_TIMEOUT', 20)),
    ],

    'avm_v2_v1' => [
        'enabled' => env('AVM_V2_V1_ENABLED', false),
        'url' => env('AVM_SERVICE_URL', 'http://127.0.0.1:8003'),
        'token' => env('AVM_SERVICE_TOKEN'),
        'timeout' => env('AVM_V2_V1_TIMEOUT', env('AVM_SERVICE_TIMEOUT', 20)),
    ],

    'nominatim' => [
        'url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'GrupoMundoPatrimonialValuador/1.0'),
        'timeout' => env('NOMINATIM_TIMEOUT', 8),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'timeout' => 5,
    ],

];
