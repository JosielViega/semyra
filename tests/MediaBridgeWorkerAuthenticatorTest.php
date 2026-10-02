<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Request;
use App\Services\MediaBridgeWorkerAuthenticator;
use PHPUnit\Framework\TestCase;

final class MediaBridgeWorkerAuthenticatorTest extends TestCase
{
    private const SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testDisabledMissingWrongAndMalformedSecretsAreIndistinguishable(): void
    {
        self::assertFalse($this->auth(false)->accepts($this->request(self::SECRET)));
        self::assertFalse($this->auth()->accepts($this->request(null)));
        self::assertFalse($this->auth()->accepts($this->request(str_repeat('b', 64))));
        self::assertFalse($this->auth()->accepts($this->request('invalid')));
        self::assertFalse((new MediaBridgeWorkerAuthenticator(
            ['enabled' => true, 'worker_secret' => 'malformed'],
            'testing',
        ))->accepts($this->request(self::SECRET)));
    }

    public function testCorrectSecretUsesCustomHeader(): void
    {
        self::assertTrue($this->auth()->accepts($this->request(self::SECRET)));
    }

    public function testProductionRequiresHttpsButLocalhostMayUseHttp(): void
    {
        self::assertFalse($this->auth(environment: 'production')->accepts($this->request(self::SECRET)));
        self::assertTrue($this->auth(environment: 'production')->accepts($this->request(self::SECRET, true)));
        self::assertTrue($this->auth(environment: 'local')->accepts($this->request(self::SECRET)));
    }

    private function auth(bool $enabled = true, string $environment = 'testing'): MediaBridgeWorkerAuthenticator
    {
        return new MediaBridgeWorkerAuthenticator(['enabled' => $enabled, 'worker_secret' => self::SECRET], $environment);
    }

    private function request(?string $secret, bool $secure = false): Request
    {
        $server = ['HTTPS' => $secure ? 'on' : '', 'HTTP_HOST' => 'localhost:8010'];
        if ($secret !== null) {
            $server['HTTP_X_SEMYRA_WORKER_TOKEN'] = $secret;
        }
        return new Request(server: $server);
    }
}
