<?php

declare(strict_types=1);

$maxFailures = env('MEDIA_BRIDGE_MAX_FAILURES');
if ($maxFailures === null || trim((string) $maxFailures) === '') {
    $maxFailures = env('MEDIA_BRIDGE_MAX_ATTEMPTS', 3);
}

return [
    'enabled' => (bool) env('MEDIA_BRIDGE_ENABLED', false),
    'worker_secret' => trim((string) env('MEDIA_BRIDGE_WORKER_SECRET', '')),
    'lease_seconds' => max(5, min(300, (int) env('MEDIA_BRIDGE_LEASE_SECONDS', 20))),
    'max_failures' => max(1, min(20, (int) $maxFailures)),
];
