<?php

declare(strict_types=1);

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;

final class LiveKitViewerTokenService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function isAvailable(): bool
    {
        if (($this->config['enabled'] ?? false) !== true) {
            return false;
        }

        $url = $this->stringValue('url');
        $parts = $url === '' ? false : parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'wss'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && $this->stringValue('api_key') !== ''
            && $this->stringValue('api_secret') !== ''
            && $this->tokenTtlSeconds() > 0;
    }

    public function serverUrl(): string
    {
        $this->assertAvailable();

        return $this->stringValue('url');
    }

    public function issue(string $roomName, string $viewerIdentity): string
    {
        $this->assertAvailable();
        if ($roomName === '' || $viewerIdentity === '') {
            throw new \InvalidArgumentException('LiveKit room and identity are required.');
        }

        $options = (new AccessTokenOptions())
            ->setIdentity($viewerIdentity)
            ->setTtl($this->tokenTtlSeconds());
        $grant = (new VideoGrant())
            ->setRoomJoin(true)
            ->setRoomName($roomName)
            ->setCanSubscribe(true)
            ->setCanPublish(false)
            ->setCanPublishData(false);

        return (new AccessToken($this->stringValue('api_key'), $this->stringValue('api_secret')))
            ->init($options)
            ->setGrant($grant)
            ->toJwt();
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('LiveKit is unavailable.');
        }
    }

    private function stringValue(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function tokenTtlSeconds(): int
    {
        $value = $this->config['token_ttl_seconds'] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
