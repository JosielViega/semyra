<?php

declare(strict_types=1);

$namespace = trim((string) env('LIVEKIT_NAMESPACE', ''));
if ($namespace === '') {
    $namespace = trim((string) env('APP_ENV', 'production'));
}
if ($namespace === '') {
    $namespace = 'production';
}

return [
    'enabled' => (bool) env('LIVEKIT_ENABLED', false),
    'url' => trim((string) env('LIVEKIT_URL', '')),
    'api_key' => trim((string) env('LIVEKIT_API_KEY', '')),
    'api_secret' => trim((string) env('LIVEKIT_API_SECRET', '')),
    'namespace' => $namespace,
    'token_ttl_seconds' => 600,
];
