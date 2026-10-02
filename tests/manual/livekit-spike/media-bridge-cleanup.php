<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();
if (!in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true)) {
    fwrite(STDERR, "Refusing to run outside APP_ENV=local/testing.\n");
    exit(1);
}

$fixtureScript = __DIR__ . '/media-bridge-job-fixture.php';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixtureScript) . ' stop', $stopExit);
if ($stopExit !== 0) {
    exit($stopExit);
}
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/semyra-room-fixture.php') . ' end', $endExit);
exit($endExit);
