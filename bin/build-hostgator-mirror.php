<?php

declare(strict_types=1);

use TemplateTools\HostgatorMirrorBuilder;

require __DIR__ . '/lib/HostgatorMirrorBuilder.php';

$root = dirname(__DIR__);
$manifest = require $root . '/deploy/hostgator/config/deploy.php';
$composerBinary = getenv('COMPOSER_BINARY');

if (!is_string($composerBinary) || $composerBinary === '') {
    $composerBinary = 'composer';
}

fwrite(STDOUT, 'Building HostGator deployment mirror...' . PHP_EOL . PHP_EOL);

try {
    $result = (new HostgatorMirrorBuilder($root, $manifest, $composerBinary))->build();
    fwrite(STDOUT, PHP_EOL . "Mirror ready: deploy/hostgator/mirror/ ({$result['files']} files)" . PHP_EOL);
    fwrite(STDOUT, 'No upload or remote deletion was performed.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, PHP_EOL . 'DEPLOY BUILD FAILED' . PHP_EOL);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
