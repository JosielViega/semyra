<?php

declare(strict_types=1);

namespace App\Services;

use Agence104\LiveKit\IngressServiceClient;
use Livekit\IngressAudioOptions;
use Livekit\IngressInput;
use Livekit\IngressState\Status;
use Livekit\IngressVideoOptions;
use Livekit\TrackSource;
use RuntimeException;
use Twirp\Error;
use Twirp\ErrorCode;

final class SdkLiveKitIngressGateway implements LiveKitIngressGateway
{
    public function __construct(
        private readonly array $config,
        private readonly ?IngressServiceClient $clientOverride = null,
    ) {
    }

    public function createWhipIngress(
        string $name,
        string $roomName,
        string $publisherIdentity,
        bool $screenShare = false,
    ): array
    {
        $audio = $screenShare ? new IngressAudioOptions([
            'name' => 'screen-audio',
            'source' => TrackSource::SCREEN_SHARE_AUDIO,
        ]) : null;
        $video = $screenShare ? new IngressVideoOptions([
            'name' => 'screen-video',
            'source' => TrackSource::SCREEN_SHARE,
        ]) : null;
        $info = $this->client()->createIngress(
            IngressInput::WHIP_INPUT,
            $name,
            $roomName,
            $publisherIdentity,
            '',
            $audio,
            $video,
            true,
        );
        $ingressId = trim($info->getIngressId());
        $url = trim($info->getUrl());
        $streamKey = trim($info->getStreamKey());
        if ($info->getInputType() !== IngressInput::WHIP_INPUT
            || !$info->getBypassTranscoding()
            || $ingressId === ''
            || $url === ''
            || $streamKey === '') {
            throw new RuntimeException('LiveKit WHIP ingress response is incomplete.');
        }

        return [
            'ingress_id' => $ingressId,
            'whip_endpoint' => rtrim($url, '/') . '/' . rawurlencode($streamKey),
        ];
    }

    public function deleteIngress(string $ingressId): void
    {
        if ($ingressId === '') {
            throw new RuntimeException('LiveKit ingress ID is invalid.');
        }
        try {
            $this->client()->deleteIngress($ingressId);
        } catch (Error $exception) {
            if ($exception->getErrorCode() !== ErrorCode::NotFound) {
                throw $exception;
            }
        }
    }

    public function findOwnedIngressIds(string $roomName, string $ingressName, string $publisherIdentity): array
    {
        if ($roomName === '' || $ingressName === '' || $publisherIdentity === '') {
            throw new RuntimeException('LiveKit ingress ownership context is invalid.');
        }

        $ids = [];
        foreach ($this->client()->listIngress($roomName)->getItems() as $info) {
            if ($info->getRoomName() !== $roomName
                || $info->getName() !== $ingressName
                || $info->getParticipantIdentity() !== $publisherIdentity
                || $info->getInputType() !== IngressInput::WHIP_INPUT) {
                continue;
            }
            $ingressId = trim($info->getIngressId());
            if ($ingressId !== '') {
                $ids[$ingressId] = true;
            }
        }

        return array_keys($ids);
    }

    public function findIngressIdsByRoomAndName(string $roomName, string $ingressName): array
    {
        if ($roomName === '' || $ingressName === '') {
            throw new RuntimeException('LiveKit ingress ownership context is invalid.');
        }
        $ids = [];
        foreach ($this->client()->listIngress($roomName)->getItems() as $info) {
            if ($info->getRoomName() !== $roomName
                || $info->getName() !== $ingressName
                || $info->getInputType() !== IngressInput::WHIP_INPUT) {
                continue;
            }
            $id = trim($info->getIngressId());
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    public function findIngressStatus(string $roomName, string $ingressName, string $publisherIdentity, string $ingressId): ?string
    {
        if ($roomName === '' || $ingressName === '' || $publisherIdentity === '' || $ingressId === '') {
            throw new RuntimeException('LiveKit ingress status context is invalid.');
        }
        foreach ($this->client()->listIngress('', $ingressId)->getItems() as $info) {
            if ($info->getIngressId() !== $ingressId
                || $info->getRoomName() !== $roomName
                || $info->getName() !== $ingressName
                || $info->getParticipantIdentity() !== $publisherIdentity
                || $info->getInputType() !== IngressInput::WHIP_INPUT) {
                continue;
            }
            return match ($info->getState()?->getStatus()) {
                Status::ENDPOINT_BUFFERING => 'buffering',
                Status::ENDPOINT_PUBLISHING => 'publishing',
                Status::ENDPOINT_ERROR => 'error',
                Status::ENDPOINT_COMPLETE => 'complete',
                default => 'inactive',
            };
        }
        return null;
    }

    public function apiUrl(): string
    {
        $url = trim((string) ($this->config['url'] ?? ''));
        $apiUrl = preg_replace('/^wss:/i', 'https:', $url);
        if (!is_string($apiUrl) || !str_starts_with($apiUrl, 'https://')) {
            throw new RuntimeException('LiveKit API URL is invalid.');
        }

        return rtrim($apiUrl, '/');
    }

    private function client(): IngressServiceClient
    {
        if ($this->clientOverride !== null) {
            return $this->clientOverride;
        }
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        $apiSecret = trim((string) ($this->config['api_secret'] ?? ''));
        if (($this->config['enabled'] ?? false) !== true || $apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('LiveKit ingress is unavailable.');
        }

        return new IngressServiceClient($this->apiUrl(), $apiKey, $apiSecret);
    }
}
