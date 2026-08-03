<?php

return [
    'inbox_email' => env('SUPPORT_INBOX_EMAIL', env('OBSERVABILITY_ALERT_EMAIL')),
    'operator_notification_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SUPPORT_OPERATOR_EMAILS', env('OPERATIONS_ALLOWED_EMAILS', ''))),
    ))),
    'public_email' => env('SUPPORT_PUBLIC_EMAIL', 'support@socialcore.app'),
    'incident_banner' => [
        'enabled' => (bool) env('PUBLIC_INCIDENT_BANNER_ENABLED', false),
        'severity' => env('PUBLIC_INCIDENT_BANNER_SEVERITY', 'warning'),
        'message' => env('PUBLIC_INCIDENT_BANNER_MESSAGE'),
    ],
];
