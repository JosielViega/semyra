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
        self::assertTrue($this->repository->end(7, 'owner-a'));
        self::assertFalse($this->pdo->has(7));
    }

    public function testOldOwnerDoesNotRemoveReplacement(): void
    {
        $this->pdo->put(7, 'owner-b');
        self::assertFalse($this->repository->end(7, 'owner-a'));
        self::assertSame('owner-b', $this->pdo->ownerFor(7));
    }

    public function testAccountOwnerUsesUserIdAcrossKeysAndRejectsHashBypassOrLogout(): void
    {
        $this->pdo->put(7, 'old-key', ownerUserId: 42);

        self::assertFalse($this->repository->end(7, 'old-key', null));
        self::assertFalse($this->repository->end(7, 'old-key', 99));
        self::assertTrue($this->repository->end(7, 'new-key', 42));
        self::assertFalse($this->pdo->has(7));
    }

    public function testPlaybackUpdateRequiresOwnerAndBothCurrentRevisions(): void
    {
        $this->pdo->put(7, 'owner-a', 4, 6, 'vod');
        self::assertTrue($this->repository->updatePlayback(7, 'owner-a', 4, 6, 'paused', 42_000, false));
        self::assertSame('paused', $this->pdo->transmissionFor(7)['state']);
        self::assertSame(42_000, $this->pdo->transmissionFor(7)['position_ms']);
        self::assertSame(7, $this->pdo->transmissionFor(7)['playback_revision']);
    }

    public function testPlaybackUpdateRejectsWrongOwnerAndStaleRevisions(): void
    {
        $this->pdo->put(7, 'owner-b', 5, 3, 'vod');
        self::assertFalse($this->repository->updatePlayback(7, 'owner-a', 5, 3, 'paused', 42_000, false));
        self::assertFalse($this->repository->updatePlayback(7, 'owner-b', 4, 3, 'paused', 42_000, false));
        self::assertFalse($this->repository->updatePlayback(7, 'owner-b', 5, 2, 'paused', 42_000, false));
    }

    public function testAccountOwnerControlsPlaybackAndLiveEdgeAcrossParticipantKeys(): void
    {
        $this->pdo->put(7, 'old-key', 4, 6, 'vod', false, 42);
        self::assertTrue($this->repository->updatePlayback(
            7, 'new-key', 4, 6, 'paused', 42_000, false, 42,
        ));

        $this->pdo->put(7, 'old-key', 4, 6, 'live', true, 42);
        self::assertTrue($this->repository->observeLiveEdge(7, 'new-key', 4, 6, 5_954_106, 42));
        $this->pdo->put(7, 'old-key', 4, 6, 'live', true, 42);
        self::assertFalse($this->repository->observeLiveEdge(7, 'old-key', 4, 6, 5_954_106, null));
        $this->pdo->put(7, 'old-key', 4, 6, 'live', true, 42);
        self::assertFalse($this->repository->observeLiveEdge(7, 'old-key', 4, 6, 5_954_106, 99));
    }

    public function testReplacementReceivesExplicitModeAndResetsPlayback(): void
    {
        $this->repository->startOrReplace(7, 'owner-b', 'youtube', 'M7lc1UVf-VE', 'live');
        self::assertSame('live', $this->pdo->lastParams['media_mode']);
        self::assertSame(1, $this->pdo->lastParams['playback_at_live_edge']);
        self::assertStringContainsString("playback_state = 'playing'", $this->pdo->lastQuery);
        self::assertStringContainsString('playback_revision = 1', $this->pdo->lastQuery);
        self::assertStringContainsString('live_edge_position_ms = NULL', $this->pdo->lastQuery);
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
        self::assertStringContainsString('LEFT JOIN users owner_user', $this->pdo->lastQuery);
        self::assertStringContainsString('transmission.owner_user_id IS NULL', $this->pdo->lastQuery);
    }

    public function testOwnerObservationBootstrapsLiveEdgeOnlyOnceWithoutChangingRevision(): void
    {
        $this->pdo->put(7, 'owner-a', 4, 6, 'live', true);
        self::assertTrue($this->repository->observeLiveEdge(7, 'owner-a', 4, 6, 5_954_106));
        self::assertFalse($this->repository->observeLiveEdge(7, 'owner-a', 4, 6, 5_949_106));
        self::assertSame(5_954_106, $this->pdo->transmissionFor(7)['live_edge_position_ms']);
        self::assertSame(6, $this->pdo->transmissionFor(7)['playback_revision']);
        self::assertStringContainsString('live_edge_position_ms IS NULL', $this->pdo->lastQuery);
    }

    public function testLiveEdgeObservationRejectsViewerStaleRevisionsAndNonEdge(): void
    {
        $this->pdo->put(7, 'owner-a', 4, 6, 'live', true);
        self::assertFalse($this->repository->observeLiveEdge(7, 'viewer', 4, 6, 1_000));
        self::assertFalse($this->repository->observeLiveEdge(7, 'owner-a', 3, 6, 1_000));
        self::assertFalse($this->repository->observeLiveEdge(7, 'owner-a', 4, 5, 1_000));
        $this->pdo->put(7, 'owner-a', 4, 6, 'live', false);
        self::assertFalse($this->repository->observeLiveEdge(7, 'owner-a', 4, 6, 1_000));
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
    public function put(int $roomId, string $owner, int $revision = 1, int $playbackRevision = 1, string $mode = 'vod', bool $atEdge = false, ?int $ownerUserId = null): void
    {
        $this->transmissions[$roomId] = ['owner' => $owner, 'transmission_revision' => $revision,
            'playback_revision' => $playbackRevision, 'media_mode' => $mode, 'state' => 'playing',
            'position_ms' => 0, 'at_live_edge' => $atEdge, 'live_edge_position_ms' => null,
            'owner_user_id' => $ownerUserId];
    }
    public function has(int $roomId): bool { return isset($this->transmissions[$roomId]); }
    public function ownerFor(int $roomId): ?string { return $this->transmissions[$roomId]['owner'] ?? null; }
    public function transmissionFor(int $roomId): ?array { return $this->transmissions[$roomId] ?? null; }
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
            if (!$authorized) return 0;
            unset($this->transmissions[$roomId]); return 1;
        }
        if (!str_starts_with($query, 'UPDATE') || !$authorized
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
        return false;
    }
}
