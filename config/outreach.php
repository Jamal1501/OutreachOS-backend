<?php

return [
    'providers' => [
        'discovery' => env('OUTREACH_DISCOVERY_PROVIDER', 'apify'),
        'enrichment' => env('OUTREACH_ENRICHMENT_PROVIDER', 'apify'),
    ],

    'operational_db' => [
        'mode' => env('OUTREACH_OPERATIONAL_DB_MODE', 'dual'), // off|dual|database
        'auto_create_project' => env('OUTREACH_AUTO_CREATE_PROJECT', true),
    ],

    'sync' => [
        'messages' => env('OUTREACH_SYNC_MESSAGES', true),
        'crm' => env('OUTREACH_SYNC_CRM', true),
        'tasks' => env('OUTREACH_SYNC_TASKS', true),
        'outreach' => env('OUTREACH_SYNC_OUTREACH', true),
        'pipeline' => env('OUTREACH_SYNC_PIPELINE', true),
    ],


    'billing' => [
        'trial_days' => env('OUTREACH_TRIAL_DAYS', 14),
        'enrichment_credit_cost' => env('OUTREACH_ENRICHMENT_CREDIT_COST', 5),
        'ai_request_credit_cost' => env('OUTREACH_AI_REQUEST_CREDIT_COST', 1),
        'default_discovery_credit_cost' => env('OUTREACH_DEFAULT_DISCOVERY_CREDIT_COST', 25),
    ],
];
