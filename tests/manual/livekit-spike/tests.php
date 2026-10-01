<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use function Semyra\LiveKitSpike\handleTokenRequest;
use function Semyra\LiveKitSpike\createIptvViewerConnectionDetails;
use function Semyra\LiveKitSpike\validateConfig;
use const Semyra\LiveKitSpike\FIXED_ROOM_NAME;
use const Semyra\LiveKitSpike\IPTV_ROOM_NAME;
use const Semyra\LiveKitSpike\TOKEN_TTL_SECONDS;

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/ingress-lib.php';

$tests = 0;
$assertions = 0;

function assertSpike(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runSpikeTest(string $name, callable $test): void
{
    global $tests;
    $test();
    $tests++;
    fwrite(STDOUT, "PASS: {$name}\n");
}

/** @return array<string, string> */
function runtimeConfig(): array
{
    return [
        'LIVEKIT_URL' => 'wss://unit-test.invalid',
        'LIVEKIT_API_KEY' => 'test_' . bin2hex(random_bytes(12)),
        'LIVEKIT_API_SECRET' => bin2hex(random_bytes(32)),
    ];
}

runSpikeTest('GET token endpoint is rejected', function (): void {
    $result = handleTokenRequest('GET', 'application/json', '{}', []);
    assertSpike($result['status'] === 405, 'GET must return 405.');
    assertSpike(($result['headers']['Allow'] ?? '') === 'POST', 'POST must be the only allowed method.');
});

runSpikeTest('invalid JSON is rejected', function (): void {
    $result = handleTokenRequest('POST', 'application/json', '{', []);
    assertSpike($result['status'] === 400, 'Invalid JSON must return 400.');
});

runSpikeTest('missing credentials return a safe error', function (): void {
    $result = handleTokenRequest('POST', 'application/json', '{}', [
        'LIVEKIT_URL' => '',
        'LIVEKIT_API_KEY' => '',
        'LIVEKIT_API_SECRET' => '',
    ]);
    assertSpike($result['status'] === 503, 'Missing credentials must return 503.');
    assertSpike($result['body'] === ['error' => 'livekit_not_configured'], 'Missing credential details must not leak.');
});

runSpikeTest('token response has safe headers and config URL', function (): void {
    $config = runtimeConfig();
    $result = handleTokenRequest('POST', 'application/json; charset=utf-8', '{}', $config);
    assertSpike($result['status'] === 201, 'Valid request must return 201.');
    assertSpike($result['headers']['Content-Type'] === 'application/json; charset=utf-8', 'JSON content type is required.');
    assertSpike($result['headers']['Cache-Control'] === 'no-store', 'Token response must not be cached.');
    assertSpike($result['body']['server_url'] === $config['LIVEKIT_URL'], 'Server URL must come from config.');
});

runSpikeTest('credentials are not explicitly exposed', function (): void {
    $config = runtimeConfig();
    $result = handleTokenRequest('POST', 'application/json', '{}', $config);
    $bodyJson = json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $publicFields = $result['body'];
    unset($publicFields['participant_token']);
    $publicJson = json_encode($publicFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    assertSpike(!str_contains($bodyJson, $config['LIVEKIT_API_SECRET']), 'API secret must never appear in the response.');
    assertSpike(!str_contains($publicJson, $config['LIVEKIT_API_KEY']), 'API key must not appear outside the participant token.');
    assertSpike(array_keys($result['body']) === ['server_url', 'participant_token'], 'Response must contain only connection fields.');
});

runSpikeTest('identity, TTL and grants are constrained', function (): void {
    $config = runtimeConfig();
    $result = handleTokenRequest('POST', 'application/json', '{}', $config);
    $claims = JWT::decode($result['body']['participant_token'], new Key($config['LIVEKIT_API_SECRET'], 'HS256'));
    assertSpike(preg_match('/^lkspike_[a-f0-9]{24}$/', (string) $claims->sub) === 1, 'Identity must be opaque.');
    assertSpike(($claims->exp - $claims->iat) === TOKEN_TTL_SECONDS, 'TTL must be exactly ten minutes.');
    assertSpike($claims->video->room === FIXED_ROOM_NAME, 'Room must be fixed by the backend.');
    assertSpike($claims->video->roomJoin === true, 'roomJoin must be true.');
    assertSpike($claims->video->canPublish === true, 'canPublish must be true.');
    assertSpike($claims->video->canSubscribe === true, 'canSubscribe must be true.');
    assertSpike($claims->video->canPublishData === false, 'Data publishing must not be granted.');
    assertSpike($claims->video->canPublishSources === ['microphone'], 'Only microphone publishing must be granted.');
    assertSpike(!isset($claims->video->roomAdmin), 'Room administration must not be granted.');
});

runSpikeTest('participant identities are distinct', function (): void {
    $config = runtimeConfig();
    $first = handleTokenRequest('POST', 'application/json', '{}', $config);
    $second = handleTokenRequest('POST', 'application/json', '{}', $config);
    $firstClaims = JWT::decode($first['body']['participant_token'], new Key($config['LIVEKIT_API_SECRET'], 'HS256'));
    $secondClaims = JWT::decode($second['body']['participant_token'], new Key($config['LIVEKIT_API_SECRET'], 'HS256'));
    assertSpike($firstClaims->sub !== $secondClaims->sub, 'Every token must receive a distinct identity.');
});

runSpikeTest('client cannot choose a room or identity', function (): void {
    $config = runtimeConfig();
    $result = handleTokenRequest('POST', 'application/json', '{"room_name":"other","participant_identity":"person"}', $config);
    assertSpike($result['status'] === 400, 'Client-controlled room or identity must be rejected.');
    assertSpike($result['body'] === ['error' => 'unsupported_fields'], 'Rejected fields must receive a safe error.');
});

runSpikeTest('IPTV viewer token is subscribe-only', function (): void {
    $config = runtimeConfig();
    $result = createIptvViewerConnectionDetails(validateConfig($config));
    $claims = JWT::decode($result['participant_token'], new Key($config['LIVEKIT_API_SECRET'], 'HS256'));
    assertSpike($result['server_url'] === $config['LIVEKIT_URL'], 'Viewer server URL must come from config.');
    assertSpike($claims->video->room === IPTV_ROOM_NAME, 'Viewer room must be fixed by the backend.');
    assertSpike($claims->video->roomJoin === true, 'Viewer must be allowed to join.');
    assertSpike($claims->video->canSubscribe === true, 'Viewer must be allowed to subscribe.');
    assertSpike($claims->video->canPublish === false, 'Viewer must not publish media.');
    assertSpike($claims->video->canPublishData === false, 'Viewer must not publish data.');
});

fwrite(STDOUT, "LiveKit spike tests: {$tests} passed, {$assertions} assertions.\n");
