<?php

declare(strict_types=1);

namespace Tests;

use App\Services\LiveKitVoiceTokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

final class LiveKitVoiceTokenServiceTest extends TestCase
{
    private const API_KEY = 'DUMMY_VOICE_TEST_KEY';
    private const API_SECRET = 'DUMMY_VOICE_TEST_SECRET_NOT_REAL_0123456789ABCDEF';

    public function testVoiceTokenCanOnlyPublishMicrophoneAndCannotPublishData(): void
    {
        $service = new LiveKitVoiceTokenService($this->config());
        $token = $service->issue('smy_r_' . str_repeat('a', 32), 'smy_c_' . str_repeat('b', 32), 'Pedro');
        $claims = JWT::decode($token, new Key(self::API_SECRET, 'HS256'));

        self::assertSame(600, $claims->exp - $claims->iat);
        self::assertSame('Pedro', $claims->name);
        self::assertTrue($claims->video->roomJoin);
        self::assertTrue($claims->video->canSubscribe);
        self::assertTrue($claims->video->canPublish);
        self::assertFalse($claims->video->canPublishData);
        self::assertSame(['microphone'], $claims->video->canPublishSources);
        self::assertObjectNotHasProperty('ingressAdmin', $claims->video);
        self::assertObjectNotHasProperty('roomAdmin', $claims->video);
    }

    public function testVoiceServiceRejectsIncompleteOrInsecureConfiguration(): void
    {
        self::assertFalse((new LiveKitVoiceTokenService(array_merge($this->config(), ['api_secret' => ''])))->isAvailable());
        self::assertFalse((new LiveKitVoiceTokenService(array_merge($this->config(), ['url' => 'https://unit-test.invalid'])))->isAvailable());
        self::assertFalse((new LiveKitVoiceTokenService(array_merge($this->config(), ['enabled' => false])))->isAvailable());
    }

    public function testVoiceTokenRequiresIdentityAndDisplayName(): void
    {
        $service = new LiveKitVoiceTokenService($this->config());
        $this->expectException(\InvalidArgumentException::class);
        $service->issue('room', 'identity', '');
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'enabled' => true,
            'url' => 'wss://unit-test.invalid',
            'api_key' => self::API_KEY,
            'api_secret' => self::API_SECRET,
            'token_ttl_seconds' => 600,
        ];
    }
}
