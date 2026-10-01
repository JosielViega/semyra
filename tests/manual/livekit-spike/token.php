<?php

declare(strict_types=1);

use function Semyra\LiveKitSpike\handleTokenRequest;

require_once __DIR__ . '/lib.php';

$result = handleTokenRequest(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['CONTENT_TYPE'] ?? '',
    (string) file_get_contents('php://input'),
);

http_response_code($result['status']);
foreach ($result['headers'] as $name => $value) {
    header($name . ': ' . $value);
}

echo json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
