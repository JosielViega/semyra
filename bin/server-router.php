<?php

declare(strict_types=1);

$public = dirname(__DIR__) . '/public';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$requested = realpath($public . DIRECTORY_SEPARATOR . ltrim($path, '/'));

if ($requested !== false
    && str_starts_with($requested, realpath($public) . DIRECTORY_SEPARATOR)
    && is_file($requested)
) {
    return false;
}

require $public . '/index.php';
