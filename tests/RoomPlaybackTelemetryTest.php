<?php

declare(strict_types=1);

namespace Tests;

use App\Services\RoomPlaybackTelemetry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoomPlaybackTelemetryTest extends TestCase
{
    private RoomPlaybackTelemetry $telemetry;

    protected function setUp(): void
    {
        $this->telemetry = new RoomPlaybackTelemetry();
    }

    #[DataProvider('validStateProvider')]
    public function testAcceptsDocumentedPlayerStates(int $state): void
    {
        self::assertSame([
            'state' => $state,
            'position_ms' => 125_400,
            'duration_ms' => 601_200,
        ], $this->telemetry->normalizePayload([
            'player_state' => (string) $state,
            'player_position_ms' => '125400',
            'player_duration_ms' => '601200',
        ]));
    }

    public static function validStateProvider(): array
    {
        return [[-1], [0], [1], [2], [3], [5]];
    }

    public function testRejectsUndocumentedStateFour(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->telemetry->normalizePayload([
            'player_state' => '4',
            'player_position_ms' => '0',
            'player_duration_ms' => '0',
        ]);
    }

    public function testRejectsPartialPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->telemetry->normalizePayload([
            'player_state' => '1',
            'player_position_ms' => '1000',
        ]);
    }

    public function testRejectsNegativeTimes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->telemetry->normalizePayload([
            'player_state' => '1',
            'player_position_ms' => '-1',
            'player_duration_ms' => '1000',
        ]);
    }

    public function testRejectsTimesAboveTheDocumentedTenYearLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->telemetry->normalizePayload([
            'player_state' => '1',
            'player_position_ms' => (string) (RoomPlaybackTelemetry::MAX_TIME_MS + 1),
            'player_duration_ms' => '1000',
        ]);
    }

    public function testAcceptsAbsentTelemetryAsNull(): void
    {
        self::assertNull($this->telemetry->normalizePayload([]));
    }

    public function testAcceptsOptionalValidPlayerInstanceId(): void
    {
        self::assertNull($this->telemetry->normalizePlayerInstanceId(null));
        self::assertSame(
            '0123456789abcdef0123456789abcdef',
            $this->telemetry->normalizePlayerInstanceId('0123456789abcdef0123456789abcdef'),
        );
    }

    #[DataProvider('invalidPlayerInstanceProvider')]
    public function testRejectsInvalidPlayerInstanceId(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->telemetry->normalizePlayerInstanceId($value);
    }

    public static function invalidPlayerInstanceProvider(): array
    {
        return [
            [''],
            ['ABCDEF0123456789abcdef0123456789'],
            ['0123456789abcdef0123456789abcde'],
            ['0123456789abcdef0123456789abcdef0'],
            [123],
        ];
    }

    public function testAcceptsRealLiveSnapshotWherePositionExceedsReportedDuration(): void
    {
        self::assertSame([
            'state' => 1,
            'position_ms' => 2_890_000,
            'duration_ms' => 2_827_000,
        ], $this->telemetry->normalizePayload([
            'player_state' => '1',
            'player_position_ms' => '2890000',
            'player_duration_ms' => '2827000',
        ]));
    }

    public function testFreshPlayingSnapshotsAreProjectedAndProduceSignedDrift(): void
    {
        $participants = $this->telemetry->presentParticipants([
            $this->row('current', 'Josiel', 1, 125_400, 601_200, 200),
            $this->row('ahead', 'Pedro', 1, 127_000, 601_200, 300),
            $this->row('behind', 'Ana', 1, 123_800, 601_200, 100),
        ], 'current');

        self::assertSame(125_600, $participants[0]['playback']['position_ms']);
        self::assertSame(0, $participants[0]['playback']['drift_ms']);
        self::assertSame(127_300, $participants[1]['playback']['position_ms']);
        self::assertSame(1_700, $participants[1]['playback']['drift_ms']);
        self::assertSame(123_900, $participants[2]['playback']['position_ms']);
        self::assertSame(-1_700, $participants[2]['playback']['drift_ms']);
    }

    public function testOldSnapshotIsReturnedButNotUsedForDrift(): void
    {
        $participants = $this->telemetry->presentParticipants([
            $this->row('current', 'Josiel', 1, 125_400, 601_200, 200),
            $this->row('old', 'Pedro', 1, 123_800, 601_200, 12_001),
        ], 'current');

        self::assertFalse($participants[1]['playback']['fresh']);
        self::assertNull($participants[1]['playback']['drift_ms']);
        self::assertSame(123_800, $participants[1]['playback']['position_ms']);
    }

    public function testPlayingAndPausedParticipantsDoNotProduceDrift(): void
    {
        $participants = $this->telemetry->presentParticipants([
            $this->row('current', 'Josiel', 1, 125_400, 601_200, 200),
            $this->row('paused', 'Pedro', 2, 123_800, 601_200, 300),
        ], 'current');

        self::assertNull($participants[1]['playback']['drift_ms']);
        self::assertSame(2, $participants[1]['playback']['state']);
    }

    public function testPresentsStableOpaqueDistinctIdsWithoutInternalHashes(): void
    {
        $rows = [
            $this->row('internal-hash-a', 'Pedro', 1, 1_000, 10_000, 100),
            $this->row('internal-hash-b', 'Pedro', 1, 1_000, 10_000, 100),
        ];

        $first = $this->telemetry->presentParticipants($rows, 'internal-hash-a');
        $second = $this->telemetry->presentParticipants($rows, 'internal-hash-a');

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first[0]['public_id']);
        self::assertSame($first[0]['public_id'], $second[0]['public_id']);
        self::assertNotSame($first[0]['public_id'], $first[1]['public_id']);
        self::assertSame('Pedro', $first[0]['name']);
        self::assertSame('Pedro', $first[1]['name']);
        self::assertArrayNotHasKey('participant_key_hash', $first[0]);
        self::assertStringNotContainsString('internal-hash-a', json_encode($first, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('internal-hash-b', json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function testPresentsStablePlayerInstanceAndChangesItOnlyForNewNonce(): void
    {
        $participantHash = hash('sha256', 'participant-a');
        $firstInstanceHash = hash('sha256', '0123456789abcdef0123456789abcdef');
        $secondInstanceHash = hash('sha256', 'fedcba9876543210fedcba9876543210');

        $first = $this->telemetry->presentParticipants([
            $this->row($participantHash, 'Pedro', 1, 1_000, 10_000, 100, $firstInstanceHash),
        ], $participantHash)[0];
        $retry = $this->telemetry->presentParticipants([
            $this->row($participantHash, 'Pedro', 1, 1_000, 10_000, 100, $firstInstanceHash),
        ], $participantHash)[0];
        $reload = $this->telemetry->presentParticipants([
            $this->row($participantHash, 'Pedro', 1, 1_000, 10_000, 100, $secondInstanceHash),
        ], $participantHash)[0];

        self::assertSame($first['public_id'], $retry['public_id']);
        self::assertSame($first['public_id'], $reload['public_id']);
        self::assertSame($first['playback_instance_id'], $retry['playback_instance_id']);
        self::assertNotSame($first['playback_instance_id'], $reload['playback_instance_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first['playback_instance_id']);
        self::assertArrayNotHasKey('player_instance_key_hash', $first);
        self::assertStringNotContainsString($firstInstanceHash, json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function testPlayerInstanceIsScopedToParticipantAndNullFallsBackToPublicId(): void
    {
        $sharedInstanceHash = hash('sha256', '0123456789abcdef0123456789abcdef');
        $presented = $this->telemetry->presentParticipants([
            $this->row('participant-a', 'Pedro', 1, 1_000, 10_000, 100, $sharedInstanceHash),
            $this->row('participant-b', 'Ana', 1, 1_000, 10_000, 100, $sharedInstanceHash),
            $this->row('legacy-participant', 'Bia', 1, 1_000, 10_000, 100, null),
        ], 'participant-a');

        self::assertNotSame($presented[0]['playback_instance_id'], $presented[1]['playback_instance_id']);
        self::assertSame($presented[2]['public_id'], $presented[2]['playback_instance_id']);
    }

    /** @return array<string, int|string> */
    private function row(
        string $key,
        string $name,
        int $state,
        int $positionMs,
        int $durationMs,
        int $ageMs,
        ?string $playerInstanceKeyHash = null,
    ): array {
        return [
            'participant_key_hash' => $key,
            'player_instance_key_hash' => $playerInstanceKeyHash,
            'display_name' => $name,
            'player_state' => (string) $state,
            'player_position_ms' => (string) $positionMs,
            'player_duration_ms' => (string) $durationMs,
            'player_sample_age_ms' => (string) $ageMs,
        ];
    }
}
