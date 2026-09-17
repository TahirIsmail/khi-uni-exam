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

    /*
    | Single sign-on from kmu-cms (docs/architecture/adr-0002-sso-from-cms.md).
    | The secret is shared with the CMS (application/config/kmu_assessment.php there) and must be
    | at least 32 random bytes, base64 encoded. It never goes into git.
    */
    'kmu_cms' => [
        'url' => env('KMU_CMS_URL'),
        'sso_secret' => env('KMU_CMS_SSO_SECRET'),
        'ticket_ttl_seconds' => 60,
        'clock_skew_seconds' => 30,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
