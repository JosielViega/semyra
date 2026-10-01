<?php

declare(strict_types=1);

use function Semyra\LiveKitSpike\createIptvViewerConnectionDetails;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\validateConfig;

require_once __DIR__ . '/ingress-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

try {
    $config = validateConfig(readPrivateEnv());
    $details = createIptvViewerConnectionDetails($config);
    http_response_code(201);
    echo json_encode($details, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'viewer_token_failed']);
}
