<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\RoomTransmissionController;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\RoomParticipantSession;
use App\Services\RoomTransmissionPlayback;
use App\Services\RoomTransmissionPresenter;
use App\Services\YouTubeUrlParser;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoomTransmissionControllerTest extends TestCase
{
    private const INSTANCE_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const INSTANCE_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    #[DataProvider('invalidInstances')]
    public function testEndRejectsMissingOrInvalidInstanceId(mixed $instanceId): void
    {
        [$controller] = $this->controller([
            'transmission_instance_id' => $instanceId,
            'transmission_revision' => '1',
        ]);

        self::assertSame(422, $controller->end('ROOM1234')->status());
    }

    public static function invalidInstances(): array
    {
        return [[null], [''], ['INVALID'], [str_repeat('A', 32)], [str_repeat('a', 31)]];
    }

    public function testStaleEndFromSameOwnerDoesNotDeleteReplacementWithRestartedRevision(): void
    {
        [$controller, $pdo] = $this->controller([
            'transmission_instance_id' => self::INSTANCE_A,
            'transmission_revision' => '1',
        ], ['instance_id' => self::INSTANCE_B, 'revision' => 1]);

        self::assertSame(409, $controller->end('ROOM1234')->status());
        self::assertSame(self::INSTANCE_B, $pdo->transmission['instance_id']);
    }

    public function testEndRejectsStaleRevisionAndDeletesExactCurrentInstance(): void
    {
        [$stale, $stalePdo] = $this->controller([
            'transmission_instance_id' => self::INSTANCE_A,
            'transmission_revision' => '2',
        ]);
        self::assertSame(409, $stale->end('ROOM1234')->status());
        self::assertNotNull($stalePdo->transmission);

        [$current, $currentPdo] = $this->controller([
            'transmission_instance_id' => self::INSTANCE_A,
            'transmission_revision' => '1',
        ]);
        self::assertSame(303, $current->end('ROOM1234')->status());
        self::assertNull($currentPdo->transmission);
    }

    public function testPlaybackRejectsInvalidInstanceAndStaleInstanceOrRevision(): void
    {
        [$invalid] = $this->controller($this->playbackBody('INVALID', 1));
        self::assertPlaybackError($invalid->playback('ROOM1234'), 422, 'invalid_playback_command');

        [$staleInstance, $instancePdo] = $this->controller(
            $this->playbackBody(self::INSTANCE_A, 1),
            ['instance_id' => self::INSTANCE_B, 'revision' => 1],
        );
        self::assertPlaybackError($staleInstance->playback('ROOM1234'), 409, 'playback_conflict');
        self::assertSame('playing', $instancePdo->transmission['playback_state']);

        [$staleRevision, $revisionPdo] = $this->controller($this->playbackBody(self::INSTANCE_A, 2));
        self::assertPlaybackError($staleRevision->playback('ROOM1234'), 409, 'playback_conflict');
        self::assertSame(1, $revisionPdo->transmission['playback_revision']);
    }

    public function testPlaybackUpdatesOnlyExactCurrentContext(): void
    {
        [$controller, $pdo] = $this->controller($this->playbackBody(self::INSTANCE_A, 1));

        $response = $controller->playback('ROOM1234');
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->status());
        self::assertSame('paused', $pdo->transmission['playback_state']);
        self::assertSame(2, $pdo->transmission['playback_revision']);
        self::assertSame(self::INSTANCE_A, $body['transmission']['instance_id']);
    }

    /** @return array{RoomTransmissionController, TransmissionControllerPdo} */
    private function controller(array $body, array $transmissionOverrides = []): array
    {
        $session = new Session(false);
        $csrf = new Csrf($session);
        $participants = new RoomParticipantSession($session);
        $identity = $participants->rememberGuest('ROOM1234', 'Pedro');
        $pdo = new TransmissionControllerPdo(array_replace([
            'room_id' => 7,
            'instance_id' => self::INSTANCE_A,
            'owner_participant_key_hash' => hash('sha256', $identity['participant_key']),
            'owner_user_id' => null,
            'source_type' => 'youtube',
            'youtube_video_id' => 'M7lc1UVf-VE',
            'media_mode' => 'vod',
            'revision' => 1,
            'playback_state' => 'playing',
            'playback_position_ms' => 0,
            'playback_at_live_edge' => 0,
            'playback_revision' => 1,
            'playback_age_ms' => 0,
            'projected_live_edge_position_ms' => null,
            'started_at' => '2026-10-01 12:00:00.000',
            'updated_at' => '2026-10-01 12:00:00.000',
            'owner_name' => 'Pedro',
        ], $transmissionOverrides));
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $playback = new RoomTransmissionPlayback();

        return [new RoomTransmissionController(
            new Request(parsedBody: array_replace(['_token' => $csrf->token()], $body)),
            new View(dirname(__DIR__) . '/resources/views'),
            $session,
            $csrf,
            new RoomRepository($database),
            new RoomTransmissionRepository($database),
            $participants,
            new YouTubeUrlParser(),
            $playback,
            new RoomTransmissionPresenter($playback),
            new UserRepository($database),
            new AuthSession($session),
        ), $pdo];
    }

    private function playbackBody(string $instanceId, int $revision): array
    {
        return [
            'action' => 'pause',
            'position_ms' => '1000',
            'transmission_instance_id' => $instanceId,
            'transmission_revision' => (string) $revision,
            'playback_revision' => '1',
        ];
    }

    private function assertPlaybackError(\App\Core\Response $response, int $status, string $error): void
    {
        self::assertSame($status, $response->status());
        self::assertSame($error, json_decode($response->body(), true)['error']);
    }
}

final class TransmissionControllerPdo extends PDO
{
    public function __construct(public ?array $transmission)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TransmissionControllerStatement($this, $query);
    }
}

final class TransmissionControllerStatement extends PDOStatement
{
    private array $params = [];
    private int $affectedRows = 0;

    public function __construct(
        private readonly TransmissionControllerPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_starts_with($this->query, 'DELETE FROM room_transmissions')) {
            if ($this->matchesCurrentContext()) {
                $this->pdo->transmission = null;
                $this->affectedRows = 1;
            }
        } elseif (str_starts_with($this->query, 'UPDATE room_transmissions SET playback_state')) {
            if ($this->matchesCurrentContext()
                && $this->pdo->transmission['playback_revision'] === (int) $this->params['playback_revision']) {
                $this->pdo->transmission['playback_state'] = $this->params['playback_state'];
                $this->pdo->transmission['playback_position_ms'] = (int) $this->params['playback_position_ms'];
                $this->pdo->transmission['playback_at_live_edge'] = (int) $this->params['playback_at_live_edge'];
                ++$this->pdo->transmission['playback_revision'];
                $this->affectedRows = 1;
            }
        }

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM rooms')) {
            return ['id' => 7, 'code' => 'ROOM1234', 'created_at' => '2026-10-01 12:00:00.000'];
        }
        if (str_contains($this->query, 'FROM room_transmissions transmission')) {
            return $this->pdo->transmission ?? false;
        }

        return false;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }

    private function matchesCurrentContext(): bool
    {
        $current = $this->pdo->transmission;
        if ($current === null) {
            return false;
        }

        return $current['owner_participant_key_hash'] === ($this->params['owner_participant_key_hash'] ?? null)
            && $current['instance_id'] === ($this->params['transmission_instance_id'] ?? null)
            && $current['revision'] === (int) ($this->params['transmission_revision'] ?? 0);
    }
}
