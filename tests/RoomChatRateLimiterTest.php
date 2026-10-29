<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Session;
use App\Services\RoomChatRateLimiter;
use PHPUnit\Framework\TestCase;

final class RoomChatRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testAllowsSixDistinctMessagesAndRejectsTheSeventhInWindow(): void
    {
        $limiter = new RoomChatRateLimiter(new Session(false));
        for ($index = 1; $index <= 6; ++$index) {
            self::assertTrue($limiter->allow('ROOM1234', str_repeat('a', 64), 'message-' . $index, 100));
        }
        self::assertFalse($limiter->allow('ROOM1234', str_repeat('a', 64), 'message-7', 100));
        self::assertTrue($limiter->allow('ROOM1234', str_repeat('a', 64), 'message-8', 111));
    }

    public function testRejectsImmediateRepeatedMessageButAllowsItLater(): void
    {
        $limiter = new RoomChatRateLimiter(new Session(false));
        self::assertTrue($limiter->allow('ROOM1234', str_repeat('a', 64), 'same', 100));
        self::assertFalse($limiter->allow('ROOM1234', str_repeat('a', 64), 'same', 101));
        self::assertTrue($limiter->allow('ROOM1234', str_repeat('a', 64), 'same', 102));
    }

    public function testLimitsAreIsolatedByRoomAndParticipant(): void
    {
        $limiter = new RoomChatRateLimiter(new Session(false));
        self::assertTrue($limiter->allow('ROOM1234', str_repeat('a', 64), 'same', 100));
        self::assertTrue($limiter->allow('ROOM5678', str_repeat('a', 64), 'same', 100));
        self::assertTrue($limiter->allow('ROOM1234', str_repeat('b', 64), 'same', 100));
    }
}
