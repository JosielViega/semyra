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
            'owner_name' => 'Pedro',
            'started_at' => '2026-09-25 10:00:00',
        ], 'current');

        self::assertSame([
            'source' => 'youtube',
            'youtube_video_id' => 'M7lc1UVf-VE',
            'revision' => 4,
            'owner_name' => 'Pedro',
            'is_owner' => true,
        ], $presented);
        self::assertArrayNotHasKey('room_id', $presented);
        self::assertArrayNotHasKey('owner_participant_key_hash', $presented);
        self::assertArrayNotHasKey('started_at', $presented);
    }

    public function testUsesNeutralOwnerNameFallback(): void
    {
        $presented = (new RoomTransmissionPresenter())->present([
            'owner_participant_key_hash' => 'owner',
            'source_type' => 'youtube',
            'youtube_video_id' => 'M7lc1UVf-VE',
            'revision' => 1,
        ], 'viewer');

        self::assertSame('Participante', $presented['owner_name']);
        self::assertFalse($presented['is_owner']);
    }
}
