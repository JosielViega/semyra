<?php

declare(strict_types=1);

use TemplateTools\ProjectSetup;

require __DIR__ . '/lib/Port.php';
require __DIR__ . '/lib/PortRegistry.php';
require __DIR__ . '/lib/ProjectSetup.php';

$root = dirname(__DIR__);

try {
    $result = (new ProjectSetup($root))->run();

    fwrite(STDOUT, $result['created'] ? '.env created from .env.example.' . PHP_EOL : 'Existing .env preserved.' . PHP_EOL);
    if ($result['changed']) {
        fwrite(STDOUT, 'Local configuration updated conservatively.' . PHP_EOL);
    }
    if (!$result['urlChanged']) {
        fwrite(STDOUT, 'APP_URL was preserved.' . PHP_EOL);
    }
    if ($result['removedStale'] > 0) {
        fwrite(STDOUT, "Stale project reservations removed: {$result['removedStale']}" . PHP_EOL);
    }
    fwrite(STDOUT, "Local port ready: {$result['port']}" . PHP_EOL);
    fwrite(STDOUT, 'Reservation saved for this project.' . PHP_EOL);
    fwrite(STDOUT, 'Autoload will be refreshed next.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'SETUP FAILED: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
