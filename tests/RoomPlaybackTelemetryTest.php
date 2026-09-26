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

    /** @return array<string, int|string> */
    private function row(
        string $key,
        string $name,
        int $state,
        int $positionMs,
        int $durationMs,
        int $ageMs,
    ): array {
        return [
            'participant_key_hash' => $key,
            'display_name' => $name,
            'player_state' => (string) $state,
            'player_position_ms' => (string) $positionMs,
            'player_duration_ms' => (string) $durationMs,
            'player_sample_age_ms' => (string) $ageMs,
        ];
    }
}
