<?php

return [
    'service' => env('OBSERVABILITY_SERVICE_NAME', env('APP_NAME', 'social-core-api')),
    'environment' => env('APP_ENV', 'production'),

    'alerts' => [
        'enabled' => env('OBSERVABILITY_ALERTS_ENABLED', env('APP_ENV') === 'production'),
        'webhook_url' => env('OBSERVABILITY_ALERT_WEBHOOK_URL'),
        'email' => env('OBSERVABILITY_ALERT_EMAIL'),
        'timeout' => env('OBSERVABILITY_ALERT_TIMEOUT', 5),
    ],

    'error_tracking' => [
        'enabled' => env('OBSERVABILITY_ERROR_TRACKING_ENABLED', env('APP_ENV') === 'production'),
        'webhook_url' => env('OBSERVABILITY_ERROR_WEBHOOK_URL', env('OBSERVABILITY_ALERT_WEBHOOK_URL')),
    ],

    'health' => [
        'failed_jobs_window_minutes' => env('OBSERVABILITY_FAILED_JOBS_WINDOW_MINUTES', 15),
        'failed_jobs_threshold' => env('OBSERVABILITY_FAILED_JOBS_THRESHOLD', 0),
        'failed_webhooks_window_minutes' => env('OBSERVABILITY_FAILED_WEBHOOKS_WINDOW_MINUTES', 60),
        'failed_webhooks_threshold' => env('OBSERVABILITY_FAILED_WEBHOOKS_THRESHOLD', 0),
        'max_pending_jobs' => env('OBSERVABILITY_MAX_PENDING_JOBS', 500),
        'queue_timeout' => env('QUEUE_TIMEOUT', 3600),
    ],
];
