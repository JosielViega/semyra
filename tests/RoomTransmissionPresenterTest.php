<?php

declare(strict_types=1);

namespace Tests;

use App\Services\RoomTransmissionPresenter;
use PHPUnit\Framework\TestCase;

final class RoomTransmissionPresenterTest extends TestCase
{
    public function testReturnsNullWithoutActiveTransmission(): void
    {
        self::assertNull((new RoomTransmissionPresenter())->present(null, 'current'));
    }

    public function testPresentsOnlyPublicFieldsAndDetectsOwner(): void
    {
        $presented = (new RoomTransmissionPresenter())->present([
            'room_id' => 42,
            'owner_participant_key_hash' => 'current',
            'source_type' => 'youtube',
            'youtube_video_id' => 'M7lc1UVf-VE',
            'revision' => '4',
            'media_mode' => 'live',
            'owner_name' => 'Pedro',
            'playback_state' => 'playing',
            'playback_position_ms' => '120000',
            'playback_at_live_edge' => '1',
            'playback_revision' => '7',
            'playback_age_ms' => '430',
            'projected_live_edge_position_ms' => '8092855',
            'started_at' => '2026-09-25 10:00:00',
        ], 'current');

        self::assertSame([
            'source' => 'youtube',
            'youtube_video_id' => 'M7lc1UVf-VE',
            'revision' => 4,
            'media_mode' => 'live',
            'owner_name' => 'Pedro',
            'is_owner' => true,
            'playback' => [
                'state' => 'playing',
                'position_ms' => 8092855,
                'revision' => 7,
                'at_live_edge' => true,
                'live_edge_position_ms' => 8092855,
            ],
        ], $presented);
        self::assertArrayNotHasKey('room_id', $presented);
        self::assertArrayNotHasKey('owner_participant_key_hash', $presented);
        self::assertArrayNotHasKey('started_at', $presented);
        self::assertArrayNotHasKey('playback_position_ms', $presented);
        self::assertArrayNotHasKey('playback_age_ms', $presented);
        self::assertArrayNotHasKey('playback_updated_at', $presented);
    }

    public function testUsesNeutralOwnerNameFallback(): void
    {
        $presented = (new RoomTransmissionPresenter())->present([
            'owner_participant_key_hash' => 'owner',
            'source_type' => 'youtube',
            'youtube_video_id' => 'M7lc1UVf-VE',
            'revision' => 1,
            'media_mode' => 'unknown',
            'playback_state' => 'paused',
            'playback_position_ms' => 0,
            'playback_at_live_edge' => '0',
            'playback_revision' => 1,
            'playback_age_ms' => 500,
            'projected_live_edge_position_ms' => null,
        ], 'viewer');

        self::assertSame('Participante', $presented['owner_name']);
        self::assertFalse($presented['playback']['at_live_edge']);
        self::assertSame(0, $presented['playback']['position_ms']);
        self::assertFalse($presented['is_owner']);
    }
}
