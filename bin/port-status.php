<?php

declare(strict_types=1);

use TemplateTools\Port;
use TemplateTools\PortRegistry;

require __DIR__ . '/lib/Port.php';
require __DIR__ . '/lib/PortRegistry.php';

$root = dirname(__DIR__);

try {
    $registry = PortRegistry::forCurrentUser();
    $project = $registry->normalizeProjectPath($root);
    $reservedPort = $registry->reservationFor($root);
    $environmentPort = null;
    $environmentFile = $root . '/.env';

    if (is_file($environmentFile)) {
        $contents = file_get_contents($environmentFile);
        if ($contents === false) {
            throw new RuntimeException('Unable to read .env.');
        }
        if (preg_match('/^APP_PORT=(.*)$/m', $contents, $matches) === 1) {
            $value = trim(trim($matches[1]), "\"'");
            $environmentPort = Port::isValid($value) ? (int) $value : null;
        }
    }

    $statusPort = $reservedPort ?? $environmentPort;
    $portStatus = $statusPort === null
        ? 'not configured'
        : (Port::isAvailable($statusPort) ? 'available' : 'occupied by a listener');

    fwrite(STDOUT, "Project: {$project}" . PHP_EOL);
    fwrite(STDOUT, 'Reserved port: ' . ($reservedPort ?? 'none') . PHP_EOL);
    fwrite(STDOUT, '.env APP_PORT: ' . ($environmentPort ?? 'missing or invalid') . PHP_EOL);
    fwrite(STDOUT, "Port status: {$portStatus}" . PHP_EOL);

    if ($reservedPort !== $environmentPort) {
        fwrite(STDOUT, 'Inconsistency detected. Run composer setup.' . PHP_EOL);
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'PORT STATUS FAILED: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
