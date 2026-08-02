<?php

return [
    'inbox_email' => env('SUPPORT_INBOX_EMAIL', env('OBSERVABILITY_ALERT_EMAIL')),
    'public_email' => env('SUPPORT_PUBLIC_EMAIL', 'support@socialcore.app'),
    'incident_banner' => [
        'enabled' => (bool) env('PUBLIC_INCIDENT_BANNER_ENABLED', false),
        'severity' => env('PUBLIC_INCIDENT_BANNER_SEVERITY', 'warning'),
        'message' => env('PUBLIC_INCIDENT_BANNER_MESSAGE'),
    ],
];
