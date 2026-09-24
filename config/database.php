<?php

declare(strict_types=1);

return [
    'host' => (string) env('DB_HOST', 'localhost'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => (string) env('DB_DATABASE', ''),
    'username' => (string) env('DB_USERNAME', ''),
    'password' => (string) env('DB_PASSWORD', ''),
    'charset' => (string) env('DB_CHARSET', 'utf8mb4'),
];
