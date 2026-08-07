<?php

return [
    'launch' => [
        'invite_only' => env('ACCESS_INVITE_ONLY', false),
        'require_verified_email' => env('ACCESS_REQUIRE_VERIFIED_EMAIL', true),
        'allowed_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('ACCESS_ALLOWED_EMAILS', ''))))),
        'allowed_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('ACCESS_ALLOWED_DOMAINS', ''))))),
        'enable_raw_scraper' => env('FEATURE_RAW_SCRAPER', false),
        'enable_tiktok' => env('FEATURE_TIKTOK', true),
    ],
    'providers' => [
        'discovery' => env('OUTREACH_DISCOVERY_PROVIDER', 'apify'),
        'enrichment' => env('OUTREACH_ENRICHMENT_PROVIDER', 'apify'),
    ],

    'provider_spend' => [
        'enabled' => env('PROVIDER_SPEND_LIMITS_ENABLED', true),
        'global_daily_limit_usd' => env('PROVIDER_SPEND_GLOBAL_DAILY_LIMIT_USD', 50),
        'workspace_daily_limit_usd' => env('PROVIDER_SPEND_WORKSPACE_DAILY_LIMIT_USD', 20),
        'openai_reservation_usd' => env('PROVIDER_SPEND_OPENAI_RESERVATION_USD', 0.10),
        'operator_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPERATIONS_ALLOWED_EMAILS', ''))))),
    ],

    'operational_db' => [
        'mode' => env('OUTREACH_OPERATIONAL_DB_MODE', 'database'), // off|dual|database
        'auto_create_project' => env('OUTREACH_AUTO_CREATE_PROJECT', true),
    ],

    'sync' => [
        'messages' => env('OUTREACH_SYNC_MESSAGES', false),
        'crm' => env('OUTREACH_SYNC_CRM', false),
        'tasks' => env('OUTREACH_SYNC_TASKS', false),
        'outreach' => env('OUTREACH_SYNC_OUTREACH', false),
        'pipeline' => env('OUTREACH_SYNC_PIPELINE', true),
    ],

    'billing' => [
        'currency' => env('OUTREACH_BILLING_CURRENCY', 'usd'),
        // Evaluation access is a one-time account allowance, not a timed trial.
        'trial_days' => 0,
        'enrichment_credit_cost' => env('OUTREACH_ENRICHMENT_CREDIT_COST', 5),
        'ai_request_credit_cost' => env('OUTREACH_AI_REQUEST_CREDIT_COST', 1),
        'default_discovery_credit_cost' => env('OUTREACH_DEFAULT_DISCOVERY_CREDIT_COST', 25),
        'stripe_webhook_tolerance' => env('OUTREACH_STRIPE_WEBHOOK_TOLERANCE', 300),
        'stripe_webhook_processing_lease_minutes' => env('OUTREACH_STRIPE_WEBHOOK_PROCESSING_LEASE_MINUTES', 10),
        'stripe_webhook_max_attempts' => env('OUTREACH_STRIPE_WEBHOOK_MAX_ATTEMPTS', 8),
        'plan_prices' => [
            'pro' => env('OUTREACH_PLAN_PRO_PRICE_CENTS', 14900),
            'enterprise' => env('OUTREACH_PLAN_ENTERPRISE_PRICE_CENTS', 39900),
        ],
        'customer_credit_value_usd' => [
            // Customer-facing ROI estimates use credit value, not internal provider COGS.
            // Margin belongs here/top-up pricing, not inside provider_cost_usd.
            'scrape' => env('OUTREACH_CUSTOMER_SCRAPE_CREDIT_VALUE_USD', 0.015),
            'ai' => env('OUTREACH_CUSTOMER_AI_CREDIT_VALUE_USD', 0.08),
        ],
        'credit_packages' => [
            [
                'id' => '11111111-1111-4111-8111-111111111111',
                'name' => 'Extra Workflow Pack',
                'scrape_credits' => 500,
                'ai_credits' => 50,
                'price_usd' => 15.00,
                'allowed_plan_ids' => ['pro', 'enterprise'],
            ],
            [
                'id' => '22222222-2222-4222-8222-222222222222',
                'name' => 'Growth Workflow Pack',
                'scrape_credits' => 2000,
                'ai_credits' => 250,
                'price_usd' => 49.00,
                'allowed_plan_ids' => ['pro', 'enterprise'],
            ],
            [
                'id' => '33333333-3333-4333-8333-333333333333',
                'name' => 'Scale Workflow Pack',
                'scrape_credits' => 6000,
                'ai_credits' => 800,
                'price_usd' => 119.00,
                'allowed_plan_ids' => ['pro', 'enterprise'],
            ],
        ],
    ],
];
