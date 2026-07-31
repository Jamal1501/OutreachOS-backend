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
        'allow_legacy_key' => env('ALLOW_LEGACY_APP_KEY', false),
    ],

    'supabase' => [
        'url' => env('SUPABASE_URL', env('VITE_SUPABASE_URL')),
        'anon_key' => env('SUPABASE_ANON_KEY', env('VITE_SUPABASE_PUBLISHABLE_KEY')),
        'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
        'auth_timeout' => env('SUPABASE_AUTH_TIMEOUT', 15),
    ],

    'ai' => [
        'openai_key' => env('OPENAI_API_KEY'),
        'openai_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'timeout' => env('AI_TIMEOUT', 60),
    ],

    'apify' => [
        'token' => env('APIFY_API_TOKEN'),
        'default_max_total_charge_usd' => env('APIFY_DEFAULT_MAX_TOTAL_CHARGE_USD', 5.00),
        'max_charge_hard_ceiling_usd' => env('APIFY_MAX_CHARGE_HARD_CEILING_USD', 10.00),
        'actors' => [
            'instagram_discovery' => env('APIFY_ACTOR_INSTAGRAM_DISCOVERY'),
            'tiktok_discovery' => env('APIFY_ACTOR_TIKTOK_DISCOVERY'),
            'instagram_profile' => env('APIFY_ACTOR_INSTAGRAM_PROFILE'),
            'tiktok_profile' => env('APIFY_ACTOR_TIKTOK_PROFILE'),
            'instagram_content_deep' => env('APIFY_ACTOR_INSTAGRAM_CONTENT_DEEP'),
            'tiktok_comments_deep' => env('APIFY_ACTOR_TIKTOK_COMMENTS_DEEP'),
        ],
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
