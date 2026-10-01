<?php

declare(strict_types=1);

namespace Tests;

use App\Services\LiveKitViewerTokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

final class LiveKitViewerTokenServiceTest extends TestCase
{
    private const API_KEY = 'DUMMY_TEST_API_KEY';
    private const API_SECRET = 'DUMMY_TEST_API_SECRET_NOT_REAL_0123456789ABCDEF';

    public function testDisabledOrIncompleteConfigurationIsUnavailableWithoutThrowing(): void
    {
        self::assertFalse((new LiveKitViewerTokenService([
            'enabled' => false,
            'url' => '',
            'api_key' => '',
            'api_secret' => '',
            'token_ttl_seconds' => 600,
        ]))->isAvailable());
        self::assertFalse((new LiveKitViewerTokenService([
            'enabled' => true,
            'url' => 'wss://unit-test.invalid',
            'api_key' => '',
            'api_secret' => '',
            'token_ttl_seconds' => 600,
        ]))->isAvailable());
        self::assertFalse((new LiveKitViewerTokenService([
            'enabled' => true,
            'url' => 'https://unit-test.invalid',
            'api_key' => self::API_KEY,
            'api_secret' => self::API_SECRET,
            'token_ttl_seconds' => 600,
        ]))->isAvailable());
    }

    public function testViewerTokenHasShortSubscribeOnlyGrant(): void
    {
        $service = new LiveKitViewerTokenService($this->config());
        $token = $service->issue('smy_r_0123456789abcdef0123456789abcdef', 'smy_v_' . str_repeat('a', 48));
        $claims = JWT::decode($token, new Key(self::API_SECRET, 'HS256'));

        self::assertSame(600, $claims->exp - $claims->iat);
        self::assertSame('smy_v_' . str_repeat('a', 48), $claims->sub);
        self::assertSame('smy_r_0123456789abcdef0123456789abcdef', $claims->video->room);
        self::assertTrue($claims->video->roomJoin);
        self::assertTrue($claims->video->canSubscribe);
        self::assertFalse($claims->video->canPublish);
        self::assertFalse($claims->video->canPublishData);
        self::assertObjectNotHasProperty('ingressAdmin', $claims->video);
        self::assertObjectNotHasProperty('roomAdmin', $claims->video);
        self::assertObjectNotHasProperty('roomCreate', $claims->video);
        self::assertObjectNotHasProperty('roomList', $claims->video);
        self::assertObjectNotHasProperty('roomRecord', $claims->video);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'enabled' => true,
            'url' => 'wss://unit-test.invalid',
            'api_key' => self::API_KEY,
            'api_secret' => self::API_SECRET,
            'namespace' => 'testing',
            'token_ttl_seconds' => 600,
        ];
    }
}
