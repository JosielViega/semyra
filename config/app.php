<?php

declare(strict_types=1);

$rememberDays = filter_var(env('AUTH_REMEMBER_DAYS', 30), FILTER_VALIDATE_INT);
if ($rememberDays === false || $rememberDays < 1 || $rememberDays > 90) {
    $rememberDays = 30;
}

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
    'auth' => [
        'remember_days' => $rememberDays,
    ],
];
