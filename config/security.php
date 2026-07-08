<?php

return [
    'csp' => [
        'enabled' => env('SECURITY_CSP_ENABLED', true),
        'report_only' => env('SECURITY_CSP_REPORT_ONLY', false),
        'report_uri' => env('SECURITY_CSP_REPORT_URI', '/api/csp-report'),
        'connect_src' => array_filter(array_map('trim', explode(',', env(
            'SECURITY_CSP_CONNECT_SRC',
            "'self',https://*.supabase.co,https://loveframes-outreach-api-1.onrender.com,https://*.onrender.com"
        )))),
        'img_src' => array_filter(array_map('trim', explode(',', env(
            'SECURITY_CSP_IMG_SRC',
            "'self',data:,blob:,https:"
        )))),
        'style_src' => array_filter(array_map('trim', explode(',', env(
            'SECURITY_CSP_STYLE_SRC',
            "'self','unsafe-inline',https://fonts.googleapis.com"
        )))),
        'font_src' => array_filter(array_map('trim', explode(',', env(
            'SECURITY_CSP_FONT_SRC',
            "'self',data:,https://fonts.gstatic.com"
        )))),
    ],
];
