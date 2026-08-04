<?php

$extraAllowedOrigins = array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', ''))));
$production = env('APP_ENV') === 'production';
$defaultAllowedOrigins = [
    'https://socialcore.app',
    'https://www.socialcore.app',
];

if (! $production) {
    $defaultAllowedOrigins = array_merge($defaultAllowedOrigins, [
        'http://127.0.0.1:8080',
        'http://localhost:8080',
        'http://192.168.2.218:8080',
    ]);
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_merge($defaultAllowedOrigins, $extraAllowedOrigins))),

    'allowed_origins_patterns' => $production ? [] : [
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
