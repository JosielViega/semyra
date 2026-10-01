<?php

declare(strict_types=1);

namespace Semyra\LiveKitSpike;

use Agence104\LiveKit\IngressServiceClient;
use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use Livekit\IngressInfo;
use Livekit\IngressInput;
use Livekit\IngressState\Status;
use RuntimeException;

require_once __DIR__ . '/lib.php';

const IPTV_ROOM_NAME = 'lkiptv_76d923c45a18';
const IPTV_INGRESS_IDENTITY = 'lkingress_2d89e16c04b7';
const WHIP_PRIVATE_PATH = __DIR__ . '/.private/whip-ingress.json';
const WHIP_ENV_PATH = __DIR__ . '/.private/whip-gstreamer.env';

/**
 * @param array{url: string, api_key: string, api_secret: string} $config
 * @return array{server_url: string, participant_token: string}
 */
function createIptvViewerConnectionDetails(array $config): array
{
    $options = (new AccessTokenOptions())
        ->setIdentity(opaqueParticipantIdentity())
        ->setTtl(TOKEN_TTL_SECONDS);
    $grant = (new VideoGrant())
        ->setRoomJoin(true)
        ->setRoomName(IPTV_ROOM_NAME)
        ->setCanPublish(false)
        ->setCanSubscribe(true)
        ->setCanPublishData(false);
    $token = (new AccessToken($config['api_key'], $config['api_secret']))
        ->init($options)
        ->setGrant($grant)
        ->toJwt();

    return ['server_url' => $config['url'], 'participant_token' => $token];
}

/**
 * @param array{url: string, api_key: string, api_secret: string} $config
 */
function ingressClient(array $config): IngressServiceClient
{
    $apiUrl = preg_replace('/^wss:/i', 'https:', $config['url']);
    if (!is_string($apiUrl) || !str_starts_with($apiUrl, 'https://')) {
        throw new ConfigurationException('LiveKit API URL is invalid.');
    }

    return new IngressServiceClient(rtrim($apiUrl, '/'), $config['api_key'], $config['api_secret']);
}

function ingressStateLabel(?IngressInfo $info): string
{
    $state = $info?->getState();
    if ($state === null) {
        return 'unknown';
    }

    return match ($state->getStatus()) {
        Status::ENDPOINT_INACTIVE => 'inactive',
        Status::ENDPOINT_BUFFERING => 'buffering',
        Status::ENDPOINT_PUBLISHING => 'publishing',
        Status::ENDPOINT_ERROR => 'error',
        Status::ENDPOINT_COMPLETE => 'complete',
        default => 'unknown',
    };
}

/**
 * @return array{ingress_id: string, endpoint: string, bypass_transcoding: bool, enable_transcoding: bool}
 */
function privateIngressData(IngressInfo $info): array
{
    $url = trim($info->getUrl());
    $streamKey = trim($info->getStreamKey());
    if ($info->getInputType() !== IngressInput::WHIP_INPUT || $url === '' || $streamKey === '') {
        throw new RuntimeException('WHIP ingress response is incomplete.');
    }

    return [
        'ingress_id' => $info->getIngressId(),
        'endpoint' => rtrim($url, '/') . '/' . rawurlencode($streamKey),
        'bypass_transcoding' => $info->getBypassTranscoding(),
        'enable_transcoding' => $info->getEnableTranscoding(),
    ];
}

/**
 * @param array{ingress_id: string, endpoint: string, bypass_transcoding: bool, enable_transcoding: bool} $data
 */
function writePrivateIngressData(array $data): void
{
    $directory = dirname(WHIP_PRIVATE_PATH);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create private directory.');
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents(WHIP_PRIVATE_PATH, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not write private ingress state.');
    }

    $escapedEndpoint = str_replace(["\r", "\n"], '', $data['endpoint']);
    if (file_put_contents(WHIP_ENV_PATH, 'WHIP_ENDPOINT=' . $escapedEndpoint . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not write private GStreamer environment.');
    }
}

/** @return array{ingress_id: string, endpoint: string, bypass_transcoding: bool, enable_transcoding: bool} */
function readPrivateIngressData(): array
{
    if (!is_file(WHIP_PRIVATE_PATH)) {
        throw new RuntimeException('Private ingress state is unavailable.');
    }
    $data = json_decode((string) file_get_contents(WHIP_PRIVATE_PATH), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_string($data['ingress_id'] ?? null) || $data['ingress_id'] === '') {
        throw new RuntimeException('Private ingress state is invalid.');
    }

    return $data;
}

function removePrivateIngressData(): void
{
    foreach ([WHIP_PRIVATE_PATH, WHIP_ENV_PATH] as $path) {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not remove private ingress state.');
        }
    }
}
