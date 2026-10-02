<?php

declare(strict_types=1);

namespace Tests;

use App\Services\MediaBridgeJobState;
use PHPUnit\Framework\TestCase;

final class MediaBridgeJobStateTest extends TestCase
{
    public function testOnlyDocumentedDesiredAndRuntimeStatesAreAccepted(): void
    {
        self::assertSame(['running', 'stopped'], MediaBridgeJobState::DESIRED);
        self::assertSame(
            ['pending', 'claimed', 'starting', 'running', 'stopping', 'stopped', 'failed'],
            MediaBridgeJobState::STATUSES,
        );
        self::assertTrue(MediaBridgeJobState::validDesired('running'));
        self::assertFalse(MediaBridgeJobState::validDesired('start'));
        self::assertTrue(MediaBridgeJobState::validStatus('claimed'));
        self::assertFalse(MediaBridgeJobState::validStatus('retrying'));
    }
}
