<?php
declare(strict_types=1);
namespace Tests;

use App\Services\RoomTransmissionPlayback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoomTransmissionPlaybackTest extends TestCase
{
    private RoomTransmissionPlayback $playback;
    protected function setUp(): void { $this->playback = new RoomTransmissionPlayback(); }

    public function testProjectsVodPlayingPositionUsingDatabaseAge(): void
    {
        self::assertSame(['state' => 'playing', 'position_ms' => 12_750, 'revision' => 4,
            'at_live_edge' => false, 'live_edge_position_ms' => null],
            $this->playback->present($this->row('vod', 'playing', 12_000, 4, 750)));
    }

    public function testLiveAtEdgeUsesProjectedOwnerObservation(): void
    {
        $presented = $this->playback->present($this->row('live', 'playing', 0, 4, 900, true, 5_954_106));
        self::assertSame(5_954_106, $presented['position_ms']);
        self::assertSame(5_954_106, $presented['live_edge_position_ms']);
        self::assertTrue($presented['at_live_edge']);
    }

    public function testLiveBehindEdgeProjectsOfficialPositionIndependently(): void
    {
        $presented = $this->playback->present($this->row('live', 'playing', 5_900_000, 4, 1_250, false, 5_954_106));
        self::assertSame(5_901_250, $presented['position_ms']);
        self::assertSame(5_954_106, $presented['live_edge_position_ms']);
    }

    public function testPausedPositionDoesNotAdvance(): void
    {
        self::assertSame(12_000, $this->playback->present(
            $this->row('vod', 'paused', 12_000, 4, 9_000),
        )['position_ms']);
    }

    public function testPlayingProjectionIsClampedAtMaximum(): void
    {
        self::assertSame(RoomTransmissionPlayback::MAX_TIME_MS, $this->playback->present(
            $this->row('vod', 'playing', RoomTransmissionPlayback::MAX_TIME_MS - 10, 1, 100),
        )['position_ms']);
    }

    public function testNormalizesPlayPauseSeekAndLiveWithoutLiveTarget(): void
    {
        self::assertSame('playing', $this->command('play', 'paused', 'vod', 12_000)['state']);
        self::assertSame('paused', $this->command('pause', 'playing', 'vod', 12_000)['state']);
        self::assertSame('paused', $this->command('seek', 'paused', 'live', 12_000)['state']);
        $live = $this->command('live', 'paused', 'live', 77_000, false);
        self::assertSame(77_000, $live['position_ms']);
        self::assertTrue($live['at_live_edge']);
    }

    public function testRejectsLiveActionForVod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->command('live', 'playing', 'vod', 0, false);
    }

    public function testNormalizesLiveEdgeObservationFromCurrentTimeOnly(): void
    {
        self::assertSame(['position_ms' => 8_092_855, 'transmission_revision' => 3, 'playback_revision' => 7],
            $this->playback->normalizeLiveEdgeObservation(['position_ms' => '8092855',
                'transmission_revision' => '3', 'playback_revision' => '7']));
    }

    #[DataProvider('invalidCommandProvider')]
    public function testRejectsInvalidCommand(array $payload, string $state, string $mode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->playback->normalizeCommand($payload, $state, $mode, 0);
    }

    public static function invalidCommandProvider(): array
    {
        $valid = ['action' => 'pause', 'position_ms' => '0',
            'transmission_revision' => '1', 'playback_revision' => '1'];
        return [
            'action' => [array_replace($valid, ['action' => 'rewind']), 'playing', 'vod'],
            'position' => [array_replace($valid, ['position_ms' => '-1']), 'playing', 'vod'],
            'transmission revision' => [array_replace($valid, ['transmission_revision' => '0']), 'playing', 'vod'],
            'playback revision' => [array_replace($valid, ['playback_revision' => '0']), 'playing', 'vod'],
            'state' => [$valid, 'buffering', 'vod'],
            'mode' => [$valid, 'playing', 'premiere'],
        ];
    }

    #[DataProvider('invalidObservationProvider')]
    public function testRejectsInvalidLiveEdgeObservation(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->playback->normalizeLiveEdgeObservation($payload);
    }

    public static function invalidObservationProvider(): array
    {
        $valid = ['position_ms' => '1000', 'transmission_revision' => '1', 'playback_revision' => '1'];
        return [
            'position' => [array_replace($valid, ['position_ms' => '-1'])],
            'missing position' => [array_diff_key($valid, ['position_ms' => true])],
            'transmission revision' => [array_replace($valid, ['transmission_revision' => '0'])],
            'playback revision' => [array_replace($valid, ['playback_revision' => '0'])],
        ];
    }

    #[DataProvider('invalidStoredStateProvider')]
    public function testRejectsInvalidStoredState(array $row): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->playback->present($row);
    }

    public static function invalidStoredStateProvider(): array
    {
        return [
            'invalid state' => [self::staticRow('vod', 'buffering', 0, 1, 0)],
            'negative position' => [self::staticRow('vod', 'playing', -1, 1, 0)],
            'vod at edge' => [self::staticRow('vod', 'playing', 0, 1, 0, true)],
            'invalid mode' => [self::staticRow('premiere', 'playing', 0, 1, 0)],
        ];
    }

    private function command(string $action, string $state, string $mode, int $currentPosition, bool $withPosition = true): array
    {
        $payload = ['action' => $action, 'transmission_revision' => '3', 'playback_revision' => '7'];
        if ($withPosition) $payload['position_ms'] = '12000';
        return $this->playback->normalizeCommand($payload, $state, $mode, $currentPosition);
    }

    private function row(string $mode, string $state, int $position, int $revision, int $age, bool $atEdge = false, ?int $edge = null): array
    { return self::staticRow($mode, $state, $position, $revision, $age, $atEdge, $edge); }

    private static function staticRow(string $mode, string $state, int $position, int $revision, int $age, bool $atEdge = false, ?int $edge = null): array
    {
        return ['media_mode' => $mode, 'playback_state' => $state, 'playback_position_ms' => $position,
            'playback_at_live_edge' => $atEdge ? '1' : '0', 'playback_revision' => $revision,
            'playback_age_ms' => $age, 'projected_live_edge_position_ms' => $edge];
    }
}
