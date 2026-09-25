<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\RoomRepository;
use PHPUnit\Framework\TestCase;

final class RoomRepositoryTest extends TestCase
{
    public function testTryCreateAcceptsOnlyTheRoomCode(): void
    {
        $method = new \ReflectionMethod(RoomRepository::class, 'tryCreate');

        self::assertCount(1, $method->getParameters());
        self::assertSame('code', $method->getParameters()[0]->getName());
    }
}
