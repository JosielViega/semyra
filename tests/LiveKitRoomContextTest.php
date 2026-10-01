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

    public function testPublisherIdentityIsStableForRevisionAndChangesOnReplacement(): void
    {
        $context = new LiveKitRoomContext('production');
        $startedAt = '2026-10-01 12:00:00.123';

        self::assertSame(
            $context->publisherIdentity(7, 12, $startedAt),
            $context->publisherIdentity(7, 12, $startedAt),
        );
        self::assertNotSame(
            $context->publisherIdentity(7, 12, $startedAt),
            $context->publisherIdentity(7, 13, $startedAt),
        );
        self::assertNotSame(
            $context->publisherIdentity(7, 12, $startedAt),
            $context->publisherIdentity(8, 12, $startedAt),
        );
        self::assertNotSame(
            $context->publisherIdentity(7, 12, $startedAt),
            $context->publisherIdentity(7, 12, '2026-10-01 12:00:01.123'),
        );
        $identity = $context->publisherIdentity(7, 12, $startedAt);
        self::assertMatchesRegularExpression('/^smy_i_[a-f0-9]{32}$/', $identity);
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $identity);
        self::assertStringNotContainsString('Josiel', $identity);
    }

    public function testEndThenNewStartDoesNotReusePublisherIdentityWhenRevisionRestarts(): void
    {
        $context = new LiveKitRoomContext('production');

        $firstTransmission = $context->publisherIdentity(10, 1, '2026-10-01 12:00:00.000');
        $secondTransmission = $context->publisherIdentity(10, 1, '2026-10-01 12:05:00.000');

        self::assertNotSame($firstTransmission, $secondTransmission);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStartTimestamps')]
    public function testPublisherIdentityRejectsInvalidStartTimestamp(string $startedAt): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LiveKitRoomContext('production'))->publisherIdentity(7, 1, $startedAt);
    }

    public static function invalidStartTimestamps(): array
    {
        return [[''], ['not-a-timestamp'], ['2026-13-40 25:61:61.000']];
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
