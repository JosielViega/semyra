<?php

declare(strict_types=1);

return [
    'name' => (string) env('APP_NAME', 'Semyra'),
    'environment' => (string) env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => (string) env('APP_URL', 'http://localhost'),
    'port' => (int) env('APP_PORT', 0),
    'timezone' => (string) env('APP_TIMEZONE', 'UTC'),
    'session' => [
        'name' => (string) env('SESSION_NAME', 'semyra_session'),
        'secure' => (bool) env('SESSION_SECURE', false),
    ],
];
