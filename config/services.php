<?php

return [

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

    'app_security' => [
        'key' => env('APP_API_KEY'),
    ],

    'google' => [
        'default_sheet_id' => env('GOOGLE_DEFAULT_SHEET_ID', env('GOOGLE_SHEET_ID')),
        'service_account_json' => env('GOOGLE_SERVICE_ACCOUNT_JSON'),
    ],

    'ai' => [
    'openai_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-5.4-mini'),
    'timeout' => env('AI_TIMEOUT', 45),
],
    'apify' => [
        'token' => env('APIFY_API_TOKEN'),
        'default_max_total_charge_usd' => env('APIFY_DEFAULT_MAX_TOTAL_CHARGE_USD'),
        'actors' => [
            'instagram_discovery' => env('APIFY_ACTOR_INSTAGRAM_DISCOVERY'),
            'tiktok_discovery' => env('APIFY_ACTOR_TIKTOK_DISCOVERY'),
            'instagram_profile' => env('APIFY_ACTOR_INSTAGRAM_PROFILE'),
            'tiktok_profile' => env('APIFY_ACTOR_TIKTOK_PROFILE'),
        ],
    ],

];
