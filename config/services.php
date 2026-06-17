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

    // 연동 A — respond.io (Developer API 폴링 + inbound webhook + outbound).
    'respond_io' => [
        'base_url' => env('RESPOND_BASE_URL', 'https://api.respond.io'),
        'api_token' => env('RESPOND_API_TOKEN'),
        'webhook_secret' => env('RESPOND_WEBHOOK_SECRET'),
        // 커스텀 필드 ID(바이어 회신, 드롭다운) — 워크스페이스 필드 ID 에 맞춤.
        'verdict_field' => env('RESPOND_VERDICT_FIELD', 'buyer_verdict'),
        // 드롭다운 목록값 (Jin 워크스페이스: Accept/Refuse/Hold). Hold=중립(폴러 무시).
        'verdict_values' => [
            'accept' => env('RESPOND_VERDICT_ACCEPT', 'Accept'),
            'refuse' => env('RESPOND_VERDICT_REFUSE', 'Refuse'),
            'hold' => env('RESPOND_VERDICT_HOLD', 'Hold'),
        ],
    ],

];
