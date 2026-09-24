<?php

declare(strict_types=1);

use TemplateTools\Port;
use TemplateTools\PortRegistry;

require __DIR__ . '/lib/Port.php';
require __DIR__ . '/lib/PortRegistry.php';

$root = dirname(__DIR__);

try {
    $registry = PortRegistry::forCurrentUser();
    $released = $registry->release($root);

    if ($released === null) {
        fwrite(STDOUT, 'This project had no port reservation.' . PHP_EOL);
    } else {
        fwrite(STDOUT, "Port reservation released for this project ({$released})." . PHP_EOL);
    }
    fwrite(STDOUT, '.env was not changed and no process was stopped.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'PORT RELEASE FAILED: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
