<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://lovable.dev',
        'https://croos.lovable.app',
        'https://loveframes.shop',
        'https://www.loveframes.shop',
        'http://127.0.0.1:8080',
    'http://localhost:8080',
    'http://192.168.2.218:8080',
    ],

    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.lovable\.app$#',
        '#^https://[a-z0-9-]+\.lovableproject\.com$#',
        '#^https://[a-z0-9-]+\.onrender\.com$#',
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-APP-KEY',
        'X-Workspace-Id',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
