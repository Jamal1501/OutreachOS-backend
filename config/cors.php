<?php

$extraAllowedOrigins = array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', ''))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_merge([
        'https://socialcore.app',
        'https://www.socialcore.app',

        'https://lovable.dev',
        'https://croos.lovable.app',
        'https://loveframes.shop',
        'https://www.loveframes.shop',

        'http://127.0.0.1:8080',
        'http://localhost:8080',
        'http://192.168.2.218:8080',
    ], $extraAllowedOrigins))),

    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.lovable\.app$#',
        '#^https://[a-z0-9-]+\.lovableproject\.com$#',
        '#^https://[a-z0-9-]+\.onrender\.com$#',
        '#^https://[a-z0-9-]+\.vercel\.app$#',
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];