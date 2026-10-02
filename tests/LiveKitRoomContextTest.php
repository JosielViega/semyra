<?php

declare(strict_types=1);

namespace Tests;

use App\Services\LiveKitRoomContext;
use PHPUnit\Framework\TestCase;

final class LiveKitRoomContextTest extends TestCase
{
    public function testRoomNameIsStableOpaqueAndScopedByRoomAndNamespace(): void
    {
        $context = new LiveKitRoomContext('production');
        $same = new LiveKitRoomContext('production');
        $otherNamespace = new LiveKitRoomContext('staging');

        self::assertSame($context->roomName(7), $same->roomName(7));
        self::assertNotSame($context->roomName(7), $context->roomName(8));
        self::assertNotSame($context->roomName(7), $otherNamespace->roomName(7));
        self::assertMatchesRegularExpression('/^smy_r_[a-f0-9]{32}$/', $context->roomName(7));
        self::assertStringNotContainsString('ROOM1234', $context->roomName(7));
        self::assertStringNotContainsString('Josiel', $context->roomName(7));
    }

    public function testPublisherIdentityIsStableForInstanceAndScopedByRoom(): void
    {
        $context = new LiveKitRoomContext('production');
        $firstInstance = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $secondInstance = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        self::assertSame(
            $context->publisherIdentity(7, $firstInstance),
            $context->publisherIdentity(7, $firstInstance),
        );
        self::assertNotSame(
            $context->publisherIdentity(7, $firstInstance),
            $context->publisherIdentity(7, $secondInstance),
        );
        self::assertNotSame(
            $context->publisherIdentity(7, $firstInstance),
            $context->publisherIdentity(8, $firstInstance),
        );
        $identity = $context->publisherIdentity(7, $firstInstance);
        self::assertMatchesRegularExpression('/^smy_i_[a-f0-9]{32}$/', $identity);
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $identity);
        self::assertStringNotContainsString('Josiel', $identity);
    }

    public function testEndThenNewStartDoesNotReusePublisherIdentityWhenRevisionRestarts(): void
    {
        $context = new LiveKitRoomContext('production');

        $firstTransmission = $context->publisherIdentity(10, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $secondTransmission = $context->publisherIdentity(10, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        self::assertNotSame($firstTransmission, $secondTransmission);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInstanceIds')]
    public function testPublisherIdentityRejectsInvalidInstanceId(string $instanceId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LiveKitRoomContext('production'))->publisherIdentity(7, $instanceId);
    }

    public static function invalidInstanceIds(): array
    {
        return [[''], ['not-hex'], [str_repeat('A', 32)], [str_repeat('a', 31)]];
    }

    public function testViewerIdentityIsNewOpaqueAsciiValueForEveryIssue(): void
    {
        $context = new LiveKitRoomContext('production');
        $first = $context->viewerIdentity();
        $second = $context->viewerIdentity();

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^smy_v_[a-f0-9]{48}$/', $first);
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $first);
        self::assertStringNotContainsString('user@example.test', $first);
        self::assertStringNotContainsString('participant-key', $first);
    }
}
