<?php

declare(strict_types=1);

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$loopbackAddresses = ['127.0.0.1', '::1'];
$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remoteAddress, $loopbackAddresses, true)) {
    respond(403, ['error' => 'local-only']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    respond(405, ['error' => 'method-not-allowed']);
}

$candidateMapPath = dirname(__DIR__, 3) . '/.iptv-spike-candidates.json';
if (!is_file($candidateMapPath)) {
    respond(503, ['error' => 'candidate-map-unavailable']);
}

try {
    $candidateMap = json_decode((string) file_get_contents($candidateMapPath), true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    respond(503, ['error' => 'candidate-map-invalid']);
}

$candidates = is_array($candidateMap['candidates'] ?? null) ? $candidateMap['candidates'] : [];
if (($_GET['action'] ?? '') === 'list') {
    $publicCandidates = [];
    foreach ($candidates as $id => $candidate) {
        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !is_array($candidate)) {
            continue;
        }
        $label = (string) ($candidate['label'] ?? '');
        if (preg_match('/^(?:LIVE_H264_[12]|LIVE_H265_1|VOD_1)$/D', $label) !== 1) {
            continue;
        }
        $publicCandidates[] = [
            'id' => $id,
            'label' => $label,
            'isLive' => (bool) ($candidate['is_live'] ?? false),
            'h265Hint' => (bool) ($candidate['h265_hint'] ?? false),
            'payload' => ($candidate['payload'] ?? '') === 'mpeg-ts' ? 'mpeg-ts' : 'unknown',
            'cors' => in_array(($candidate['cors'] ?? ''), ['*', 'specific-origin'], true)
                ? $candidate['cors']
                : 'unknown',
        ];
    }
    respond(200, ['candidates' => $publicCandidates]);
}

$candidateId = (string) ($_GET['id'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/D', $candidateId) !== 1 || !isset($candidates[$candidateId])) {
    respond(404, ['error' => 'candidate-not-found']);
}

$candidate = $candidates[$candidateId];
$url = is_array($candidate) ? (string) ($candidate['url'] ?? '') : '';
$parts = parse_url($url);
if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
    || !isset($parts['host'])) {
    respond(503, ['error' => 'candidate-invalid']);
}

respond(200, [
    'id' => $candidateId,
    'label' => (string) ($candidate['label'] ?? ''),
    'url' => $url,
    'isLive' => (bool) ($candidate['is_live'] ?? false),
    'h265Hint' => (bool) ($candidate['h265_hint'] ?? false),
    'payload' => ($candidate['payload'] ?? '') === 'mpeg-ts' ? 'mpeg-ts' : 'unknown',
    'cors' => in_array(($candidate['cors'] ?? ''), ['*', 'specific-origin'], true)
        ? $candidate['cors']
        : 'unknown',
]);
