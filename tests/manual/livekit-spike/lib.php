<?php

declare(strict_types=1);

namespace Semyra\LiveKitSpike;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use JsonException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/vendor/autoload.php';

const FIXED_ROOM_NAME = 'lkspike_4f7a9c2e';
const TOKEN_TTL_SECONDS = 600;
const PRIVATE_ENV_PATH = __DIR__ . '/.private/livekit.env';

final class ConfigurationException extends RuntimeException
{
}

/**
 * @return array<string, string>
 */
function readPrivateEnv(string $path = PRIVATE_ENV_PATH): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $values[$name] = $value;
    }

    return $values;
}

/**
 * @param array<string, string> $values
 * @return array{url: string, api_key: string, api_secret: string}
 */
function validateConfig(array $values): array
{
    $url = trim($values['LIVEKIT_URL'] ?? '');
    $apiKey = trim($values['LIVEKIT_API_KEY'] ?? '');
    $apiSecret = trim($values['LIVEKIT_API_SECRET'] ?? '');

    if ($url === '' || $apiKey === '' || $apiSecret === '') {
        throw new ConfigurationException('LiveKit configuration is incomplete.');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'wss' || ($parts['host'] ?? '') === '') {
        throw new ConfigurationException('LiveKit URL must be a valid secure WebSocket URL.');
    }

    return [
        'url' => $url,
        'api_key' => $apiKey,
        'api_secret' => $apiSecret,
    ];
}

function opaqueParticipantIdentity(): string
{
    return 'lkspike_' . bin2hex(random_bytes(12));
}

/**
 * @param array{url: string, api_key: string, api_secret: string} $config
 * @return array{server_url: string, participant_token: string}
 */
function createConnectionDetails(array $config): array
{
    $identity = opaqueParticipantIdentity();
    $options = (new AccessTokenOptions())
        ->setIdentity($identity)
        ->setTtl(TOKEN_TTL_SECONDS);

    $grant = (new VideoGrant())
        ->setRoomJoin(true)
        ->setRoomName(FIXED_ROOM_NAME)
        ->setCanPublish(true)
        ->setCanSubscribe(true)
        ->setCanPublishData(false)
        ->setCanPublishSources(['microphone']);

    $token = (new AccessToken($config['api_key'], $config['api_secret']))
        ->init($options)
        ->setGrant($grant)
        ->toJwt();

    return [
        'server_url' => $config['url'],
        'participant_token' => $token,
    ];
}

/**
 * @param array<string, string>|null $configValues
 * @return array{status: int, headers: array<string, string>, body: array<string, mixed>}
 */
function handleTokenRequest(
    string $method,
    string $contentType,
    string $rawBody,
    ?array $configValues = null,
): array {
    $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    if (strtoupper($method) !== 'POST') {
        $headers['Allow'] = 'POST';
        return ['status' => 405, 'headers' => $headers, 'body' => ['error' => 'method_not_allowed']];
    }

    $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
    if ($mediaType !== 'application/json') {
        return ['status' => 415, 'headers' => $headers, 'body' => ['error' => 'json_required']];
    }

    try {
        $decoded = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['status' => 400, 'headers' => $headers, 'body' => ['error' => 'invalid_json']];
    }

    if (!is_array($decoded) || !str_starts_with(ltrim($rawBody), '{')) {
        return ['status' => 400, 'headers' => $headers, 'body' => ['error' => 'json_object_required']];
    }

    if ($decoded !== []) {
        return ['status' => 400, 'headers' => $headers, 'body' => ['error' => 'unsupported_fields']];
    }

    try {
        $config = validateConfig($configValues ?? readPrivateEnv());
        $details = createConnectionDetails($config);
    } catch (ConfigurationException) {
        return ['status' => 503, 'headers' => $headers, 'body' => ['error' => 'livekit_not_configured']];
    } catch (Throwable) {
        return ['status' => 500, 'headers' => $headers, 'body' => ['error' => 'token_generation_failed']];
    }

    return ['status' => 201, 'headers' => $headers, 'body' => $details];
}
