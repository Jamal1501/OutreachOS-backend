<?php

return [
    'providers' => [
        'discovery' => env('OUTREACH_DISCOVERY_PROVIDER', 'apify'),
        'enrichment' => env('OUTREACH_ENRICHMENT_PROVIDER', 'apify'),
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
        'trial_days' => env('OUTREACH_TRIAL_DAYS', 14),
        'enrichment_credit_cost' => env('OUTREACH_ENRICHMENT_CREDIT_COST', 5),
        'ai_request_credit_cost' => env('OUTREACH_AI_REQUEST_CREDIT_COST', 1),
        'default_discovery_credit_cost' => env('OUTREACH_DEFAULT_DISCOVERY_CREDIT_COST', 25),
        'stripe_webhook_tolerance' => env('OUTREACH_STRIPE_WEBHOOK_TOLERANCE', 300),
        'plan_prices' => [
            'pro' => env('OUTREACH_PLAN_PRO_PRICE_CENTS', 4900),
            'enterprise' => env('OUTREACH_PLAN_ENTERPRISE_PRICE_CENTS', 14900),
        ],
        'credit_packages' => [
            [
                'id' => '11111111-1111-4111-8111-111111111111',
                'name' => 'Starter Top-up',
                'scrape_credits' => 500,
                'ai_credits' => 50,
                'price_usd' => 19.00,
                'allowed_plan_ids' => ['free', 'pro', 'enterprise'],
            ],
            [
                'id' => '22222222-2222-4222-8222-222222222222',
                'name' => 'Growth Top-up',
                'scrape_credits' => 2000,
                'ai_credits' => 250,
                'price_usd' => 69.00,
                'allowed_plan_ids' => ['free', 'pro', 'enterprise'],
            ],
            [
                'id' => '33333333-3333-4333-8333-333333333333',
                'name' => 'Scale Top-up',
                'scrape_credits' => 6000,
                'ai_credits' => 800,
                'price_usd' => 179.00,
                'allowed_plan_ids' => ['pro', 'enterprise'],
            ],
        ],
    ],
];
