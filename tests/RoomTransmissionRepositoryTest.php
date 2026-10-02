<?php
declare(strict_types=1);
namespace Tests;

use App\Core\Database;
use App\Repositories\RoomTransmissionRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class RoomTransmissionRepositoryTest extends TestCase
{
    private const INSTANCE_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const INSTANCE_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private TransmissionPdo $pdo;
    private RoomTransmissionRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new TransmissionPdo();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $this->pdo);
        $this->repository = new RoomTransmissionRepository($database);
    }

    public function testCurrentOwnerRemovesTransmission(): void
    {
        $this->pdo->put(7, 'owner-a');
        self::assertTrue($this->repository->end(7, 'owner-a', self::INSTANCE_A, 1));
        self::assertFalse($this->pdo->has(7));
    }

    public function testStaleEndFromSameOwnerDoesNotRemoveNewInstanceWithRestartedRevision(): void
    {
        $this->pdo->put(7, 'owner-a', instanceId: self::INSTANCE_B);
        self::assertFalse($this->repository->end(7, 'owner-a', self::INSTANCE_A, 1));
        self::assertSame('owner-a', $this->pdo->ownerFor(7));
    }

    public function testAccountOwnerUsesUserIdAcrossKeysAndRejectsHashBypassOrLogout(): void
    {
        $this->pdo->put(7, 'old-key', ownerUserId: 42);

        self::assertFalse($this->repository->end(7, 'old-key', self::INSTANCE_A, 1, null));
        self::assertFalse($this->repository->end(7, 'old-key', self::INSTANCE_A, 1, 99));
        self::assertTrue($this->repository->end(7, 'new-key', self::INSTANCE_A, 1, 42));
        self::assertFalse($this->pdo->has(7));
    }

    public function testPlaybackUpdateRequiresOwnerAndBothCurrentRevisions(): void
    {
        $this->pdo->put(7, 'owner-a', 4, 6, 'vod');
        self::assertTrue($this->repository->updatePlayback(7, 'owner-a', self::INSTANCE_A, 4, 6, 'paused', 42_000, false));
        self::assertSame('paused', $this->pdo->transmissionFor(7)['state']);
        self::assertSame(42_000, $this->pdo->transmissionFor(7)['position_ms']);
        self::assertSame(7, $this->pdo->transmissionFor(7)['playback_revision']);
    }

    public function testPlaybackUpdateRejectsWrongOwnerAndStaleRevisions(): void
    {
        $this->pdo->put(7, 'owner-b', 5, 3, 'vod');
        self::assertFalse($this->repository->updatePlayback(7, 'owner-a', self::INSTANCE_A, 5, 3, 'paused', 42_000, false));
        self::assertFalse($this->repository->updatePlayback(7, 'owner-b', self::INSTANCE_B, 5, 3, 'paused', 42_000, false));
        self::assertFalse($this->repository->updatePlayback(7, 'owner-b', self::INSTANCE_A, 4, 3, 'paused', 42_000, false));
        self::assertFalse($this->repository->updatePlayback(7, 'owner-b', self::INSTANCE_A, 5, 2, 'paused', 42_000, false));
    }

    public function testStalePlaybackFromEndedInstanceDoesNotChangeNewInstanceWithRestartedRevisions(): void
    {
        $this->pdo->put(7, 'owner-a', 1, 1, 'vod', instanceId: self::INSTANCE_B);

        self::assertFalse($this->repository->updatePlayback(
            7, 'owner-a', self::INSTANCE_A, 1, 1, 'paused', 42_000, false,
        ));
        self::assertSame(self::INSTANCE_B, $this->pdo->transmissionFor(7)['instance_id']);
        self::assertSame('playing', $this->pdo->transmissionFor(7)['state']);
        self::assertSame(1, $this->pdo->transmissionFor(7)['playback_revision']);
    }

    public function testAccountOwnerControlsPlaybackAndLiveEdgeAcrossParticipantKeys(): void
    {
        $this->pdo->put(7, 'old-key', 4, 6, 'vod', false, 42);
        self::assertTrue($this->repository->updatePlayback(
            7, 'new-key', self::INSTANCE_A, 4, 6, 'paused', 42_000, false, 42,
        ));

        $this->pdo->put(7, 'old-key', 4, 6, 'live', true, 42);
        self::assertTrue($this->repository->observeLiveEdge(7, 'new-key', self::INSTANCE_A, 4, 6, 5_954_106, 42));
        $this->pdo->put(7, 'old-key', 4, 6, 'live', true, 42);
        self::assertFalse($this->repository->observeLiveEdge(7, 'old-key', self::INSTANCE_A, 4, 6, 5_954_106, null));
        $this->pdo->put(7, 'old-key', 4, 6, 'live', true, 42);
        self::assertFalse($this->repository->observeLiveEdge(7, 'old-key', self::INSTANCE_A, 4, 6, 5_954_106, 99));
    }

    public function testReplacementReceivesExplicitModeAndResetsPlayback(): void
    {
        $this->repository->startOrReplace(7, 'owner-b', 'youtube', 'M7lc1UVf-VE', 'live');
        self::assertSame('live', $this->pdo->lastParams['media_mode']);
        self::assertSame(1, $this->pdo->lastParams['playback_at_live_edge']);
        self::assertStringContainsString("playback_state = 'playing'", $this->pdo->lastQuery);
        self::assertStringContainsString('playback_revision = 1', $this->pdo->lastQuery);
        self::assertStringContainsString('live_edge_position_ms = NULL', $this->pdo->lastQuery);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->pdo->lastParams['instance_id']);
        self::assertStringContainsString('instance_id = VALUES(instance_id)', $this->pdo->lastQuery);
    }

    public function testEveryStartAndReplacementGetsANewOpaqueInstanceId(): void
    {
        $this->repository->startOrReplace(7, 'owner-a', 'youtube', 'M7lc1UVf-VE', 'vod');
        $first = $this->pdo->lastParams['instance_id'];
        $this->repository->startOrReplace(7, 'owner-a', 'youtube', 'M7lc1UVf-VE', 'vod');
        $second = $this->pdo->lastParams['instance_id'];

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $second);
        self::assertNotSame($first, $second);
    }

    public function testAccountReplacementStoresOwnerUserAndGuestClearsIt(): void
    {
        $this->repository->startOrReplace(7, 'owner-a', 'youtube', 'M7lc1UVf-VE', 'vod', 42);
        self::assertSame(42, $this->pdo->lastParams['owner_user_id']);

        $this->repository->startOrReplace(7, 'guest', 'youtube', 'M7lc1UVf-VE', 'vod');
        self::assertNull($this->pdo->lastParams['owner_user_id']);
    }

    public function testLegacyClaimRequiresExactHashAndNeverReplacesAccountOwner(): void
    {
        $this->pdo->put(7, 'legacy');
        self::assertFalse($this->repository->claimOwnerAccount(7, 'wrong', 42));
        self::assertTrue($this->repository->claimOwnerAccount(7, 'legacy', 42));
        self::assertSame(42, $this->pdo->transmissionFor(7)['owner_user_id']);
        self::assertFalse($this->repository->claimOwnerAccount(7, 'legacy', 99));
    }

    public function testLookupUsesAccountNameAndOnlyFallsBackToParticipantForGuest(): void
    {
        self::assertNull($this->repository->findByRoom(7));
        self::assertStringContainsString('transmission.owner_user_id', $this->pdo->lastQuery);
        self::assertStringContainsString('transmission.instance_id', $this->pdo->lastQuery);
        self::assertStringContainsString('LEFT JOIN users owner_user', $this->pdo->lastQuery);
        self::assertStringContainsString('transmission.owner_user_id IS NULL', $this->pdo->lastQuery);
    }

    public function testLookupReturnsStoredTransmissionInstanceId(): void
    {
        $this->pdo->put(7, 'owner-a', instanceId: self::INSTANCE_B);

        self::assertSame(self::INSTANCE_B, $this->repository->findByRoom(7)['instance_id']);
    }

    public function testOwnerObservationBootstrapsLiveEdgeOnlyOnceWithoutChangingRevision(): void
    {
        $this->pdo->put(7, 'owner-a', 4, 6, 'live', true);
        self::assertTrue($this->repository->observeLiveEdge(7, 'owner-a', self::INSTANCE_A, 4, 6, 5_954_106));
        self::assertFalse($this->repository->observeLiveEdge(7, 'owner-a', self::INSTANCE_A, 4, 6, 5_949_106));
        self::assertSame(5_954_106, $this->pdo->transmissionFor(7)['live_edge_position_ms']);
        self::assertSame(6, $this->pdo->transmissionFor(7)['playback_revision']);
        self::assertStringContainsString('live_edge_position_ms IS NULL', $this->pdo->lastQuery);
    }

    public function testLiveEdgeObservationRejectsViewerStaleRevisionsAndNonEdge(): void
    {
        $this->pdo->put(7, 'owner-a', 4, 6, 'live', true);
        self::assertFalse($this->repository->observeLiveEdge(7, 'viewer', self::INSTANCE_A, 4, 6, 1_000));
        self::assertFalse($this->repository->observeLiveEdge(7, 'owner-a', self::INSTANCE_B, 4, 6, 1_000));
        self::assertFalse($this->repository->observeLiveEdge(7, 'owner-a', self::INSTANCE_A, 3, 6, 1_000));
        self::assertFalse($this->repository->observeLiveEdge(7, 'owner-a', self::INSTANCE_A, 4, 5, 1_000));
        $this->pdo->put(7, 'owner-a', 4, 6, 'live', false);
        self::assertFalse($this->repository->observeLiveEdge(7, 'owner-a', self::INSTANCE_A, 4, 6, 1_000));
        self::assertNull($this->pdo->transmissionFor(7)['live_edge_position_ms']);
    }

    public function testStaleLiveEdgeObservationDoesNotAnchorNewInstance(): void
    {
        $this->pdo->put(7, 'owner-a', 1, 1, 'live', true, instanceId: self::INSTANCE_B);

        self::assertFalse($this->repository->observeLiveEdge(
            7, 'owner-a', self::INSTANCE_A, 1, 1, 5_954_106,
        ));
        self::assertSame(self::INSTANCE_B, $this->pdo->transmissionFor(7)['instance_id']);
        self::assertNull($this->pdo->transmissionFor(7)['live_edge_position_ms']);
    }
}

final class TransmissionPdo extends PDO
{
    private array $transmissions = [];
    public string $lastQuery = '';
    public array $lastParams = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastQuery = $query;
        return new TransmissionStatement($this, $query);
    }
    public function put(int $roomId, string $owner, int $revision = 1, int $playbackRevision = 1, string $mode = 'vod', bool $atEdge = false, ?int $ownerUserId = null, string $instanceId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): void
    {
        $this->transmissions[$roomId] = ['owner' => $owner, 'instance_id' => $instanceId, 'transmission_revision' => $revision,
            'playback_revision' => $playbackRevision, 'media_mode' => $mode, 'state' => 'playing',
            'position_ms' => 0, 'at_live_edge' => $atEdge, 'live_edge_position_ms' => null,
            'owner_user_id' => $ownerUserId];
    }
    public function has(int $roomId): bool { return isset($this->transmissions[$roomId]); }
    public function ownerFor(int $roomId): ?string { return $this->transmissions[$roomId]['owner'] ?? null; }
    public function transmissionFor(int $roomId): ?array { return $this->transmissions[$roomId] ?? null; }
    public function rowForFind(int $roomId): ?array
    {
        $current = $this->transmissions[$roomId] ?? null;
        if ($current === null) return null;
        return [
            'room_id' => $roomId,
            'instance_id' => $current['instance_id'],
            'owner_participant_key_hash' => $current['owner'],
            'owner_user_id' => $current['owner_user_id'],
            'source_type' => 'youtube',
            'youtube_video_id' => 'M7lc1UVf-VE',
            'media_mode' => $current['media_mode'],
            'revision' => $current['transmission_revision'],
            'playback_state' => $current['state'],
            'playback_position_ms' => $current['position_ms'],
            'playback_at_live_edge' => $current['at_live_edge'] ? 1 : 0,
            'playback_revision' => $current['playback_revision'],
            'playback_age_ms' => 0,
            'projected_live_edge_position_ms' => $current['live_edge_position_ms'],
            'started_at' => '2026-10-01 12:00:00.000',
            'updated_at' => '2026-10-01 12:00:00.000',
            'owner_name' => 'Owner',
        ];
    }
    public function mutate(string $query, array $params): int
    {
        $this->lastParams = $params;
        $roomId = (int) ($params['room_id'] ?? 0);
        $current = $this->transmissions[$roomId] ?? null;
        if (str_contains($query, 'SET owner_user_id = :user_id')) {
            if ($current === null || $current['owner_user_id'] !== null
                || $current['owner'] !== ($params['owner_participant_key_hash'] ?? null)) return 0;
            $current['owner_user_id'] = (int) $params['user_id'];
            $this->transmissions[$roomId] = $current; return 1;
        }
        $authorized = $current !== null && ($current['owner_user_id'] !== null
            ? $current['owner_user_id'] === ($params['owner_user_id'] ?? null)
            : $current['owner'] === ($params['owner_participant_key_hash'] ?? null));
        if (str_starts_with($query, 'DELETE')) {
            if (!$authorized
                || $current['instance_id'] !== ($params['transmission_instance_id'] ?? null)
                || $current['transmission_revision'] !== (int) ($params['transmission_revision'] ?? 0)) return 0;
            unset($this->transmissions[$roomId]); return 1;
        }
        if (!str_starts_with($query, 'UPDATE') || !$authorized
            || $current['instance_id'] !== ($params['transmission_instance_id'] ?? null)
            || $current['transmission_revision'] !== (int) ($params['transmission_revision'] ?? 0)
            || $current['playback_revision'] !== (int) ($params['playback_revision'] ?? 0)) return 0;
        if (array_key_exists('live_edge_position_ms', $params)) {
            if ($current['media_mode'] !== 'live'
                || !$current['at_live_edge']
                || $current['state'] !== 'playing'
                || $current['live_edge_position_ms'] !== null) return 0;
            $current['live_edge_position_ms'] = (int) $params['live_edge_position_ms'];
        } else {
            $current['state'] = (string) $params['playback_state'];
            $current['position_ms'] = (int) $params['playback_position_ms'];
            $current['at_live_edge'] = (int) $params['playback_at_live_edge'] === 1;
            ++$current['playback_revision'];
        }
        $this->transmissions[$roomId] = $current; return 1;
    }
}

final class TransmissionStatement extends PDOStatement
{
    private int $affectedRows = 0;
    public function __construct(private readonly TransmissionPdo $pdo, private readonly string $query) {}
    public function execute(?array $params = null): bool
    {
        $params ??= [];
        if (str_starts_with($this->query, 'INSERT')) { $this->pdo->lastParams = $params; return true; }
        if ((str_starts_with($this->query, 'DELETE') || str_starts_with($this->query, 'UPDATE'))
            && !str_contains($this->query, 'owner_participant_key_hash = :owner_participant_key_hash')) {
            throw new \LogicException('Mutation must include owner hash.');
        }
        if ((str_starts_with($this->query, 'DELETE')
            || (str_starts_with($this->query, 'UPDATE')
                && !str_contains($this->query, 'SET owner_user_id = :user_id')))
            && !str_contains($this->query, 'instance_id = :transmission_instance_id')) {
            throw new \LogicException('Mutation must use transmission instance CAS.');
        }
        if (str_starts_with($this->query, 'UPDATE')
            && !str_contains($this->query, 'SET owner_user_id = :user_id')) {
            foreach (['revision = :transmission_revision', 'playback_revision = :playback_revision'] as $condition) {
                if (!str_contains($this->query, $condition)) throw new \LogicException('Mutation must use CAS revisions.');
            }
        }
        $this->affectedRows = $this->pdo->mutate($this->query, $params); return true;
    }
    public function rowCount(): int { return $this->affectedRows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return str_starts_with($this->query, 'SELECT transmission.room_id')
            ? ($this->pdo->rowForFind((int) ($this->pdo->lastParams['room_id'] ?? 0)) ?? false)
            : false;
    }
}
