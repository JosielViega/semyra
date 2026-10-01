<?php

declare(strict_types=1);

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(self)');

if ($path === '/token.php') {
    require __DIR__ . '/token.php';
    return true;
}

if ($path === '/iptv-viewer-token.php') {
    require __DIR__ . '/iptv-viewer-token.php';
    return true;
}

$assets = [
    '/' => ['index.html', 'text/html; charset=utf-8'],
    '/index.html' => ['index.html', 'text/html; charset=utf-8'],
    '/iptv-viewer.html' => ['iptv-viewer.html', 'text/html; charset=utf-8'],
    '/iptv-viewer.css' => ['iptv-viewer.css', 'text/css; charset=utf-8'],
    '/iptv-viewer.js' => ['iptv-viewer.js', 'text/javascript; charset=utf-8'],
    '/livekit-spike.css' => ['livekit-spike.css', 'text/css; charset=utf-8'],
    '/livekit-diagnostics.js' => ['livekit-diagnostics.js', 'text/javascript; charset=utf-8'],
    '/livekit-spike.js' => ['livekit-spike.js', 'text/javascript; charset=utf-8'],
    '/vendor-js/livekit-client.umd.js' => ['vendor-js/livekit-client.umd.js', 'text/javascript; charset=utf-8'],
];

if (!isset($assets[$path])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Not found';
    return true;
}

[$relativePath, $contentType] = $assets[$path];
$file = __DIR__ . '/' . $relativePath;
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return true;
}

header('Content-Type: ' . $contentType);
header('Cache-Control: no-store');
if ($contentType === 'text/html; charset=utf-8') {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self' https: wss:; media-src 'self' blob: mediastream:; img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
}
readfile($file);
return true;
