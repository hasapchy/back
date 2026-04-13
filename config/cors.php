<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        [
            'http://localhost:5173',
            'http://localhost:5174',
            'http://localhost:8080',
            'http://localhost',
            'http://127.0.0.1',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:8080',
        ],
        preg_split('/\s*,\s*/', (string) env('API_ALLOWED_ORIGINS', ''), -1, PREG_SPLIT_NO_EMPTY),
        preg_split('/\s*,\s*/', (string) env('FRONTEND_URL', ''), -1, PREG_SPLIT_NO_EMPTY),
        [env('APP_URL', '')],
    )))),

    'allowed_origins_patterns' => filter_var(env('CORS_ALLOW_LAN_ORIGINS', false), FILTER_VALIDATE_BOOLEAN)
        ? [
            '#^http://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
            '#^http://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        ]
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
