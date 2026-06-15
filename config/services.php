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

    // 연동 B — car-erp purchase-sync (board → car-erp, HMAC). 대표 승인 후 사용.
    'car_erp' => [
        'base_url' => env('CAR_ERP_BASE_URL'),
        'hmac_secret' => env('CAR_ERP_HMAC_SECRET'),
    ],

    // 연동 A — respond.io (사진/영상 전송 + inbound webhook). 도메인+HTTPS 후 사용.
    'respond_io' => [
        'api_token' => env('RESPOND_API_TOKEN'),
        'webhook_secret' => env('RESPOND_WEBHOOK_SECRET'),
    ],

];
