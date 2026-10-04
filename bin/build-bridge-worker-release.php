<?php

declare(strict_types=1);

use TemplateTools\BridgeWorkerReleaseBuilder;

require __DIR__ . '/lib/BridgeWorkerReleaseBuilder.php';

$root = dirname(__DIR__);
$output = $root . '/deploy/bridge-worker/release';

fwrite(STDOUT, "Building local bridge worker release...\n\n");

try {
    $result = (new BridgeWorkerReleaseBuilder($root, $output))->build();
    fwrite(STDOUT, "Bridge worker release ready: deploy/bridge-worker/release/ ({$result['files']} files)\n");
    fwrite(STDOUT, 'Source SHA: ' . $result['source_sha'] . "\n");
    fwrite(STDOUT, 'Working tree dirty: ' . ($result['dirty'] ? 'yes' : 'no') . "\n");
    fwrite(STDOUT, "No upload or remote action was performed.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "\nBRIDGE RELEASE BUILD FAILED\n" . $exception->getMessage() . "\n");
    exit(1);
}
