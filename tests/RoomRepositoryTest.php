<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Repositories\RoomRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class RoomRepositoryTest extends TestCase
{
    private RoomPersistencePdo $pdo;
    private RoomRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new RoomPersistencePdo();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $this->pdo);
        $this->repository = new RoomRepository($database);
    }

    public function testGuestAndAuthenticatedCreationPersistExpectedCreator(): void
    {
        self::assertTrue($this->repository->tryCreate('GUEST123', null));
        self::assertTrue($this->repository->tryCreate('USER1234', 42));

        self::assertNull($this->pdo->rooms['GUEST123']['created_by_user_id']);
        self::assertSame(42, $this->pdo->rooms['USER1234']['created_by_user_id']);
    }

    public function testDuplicateCodeStillReturnsFalse(): void
    {
        self::assertTrue($this->repository->tryCreate('ROOM1234', null));
        self::assertFalse($this->repository->tryCreate('ROOM1234', 42));
        self::assertCount(1, $this->pdo->rooms);
    }

    public function testFindReturnsActiveTemporaryAndOldPersistentButNotExpiredTemporary(): void
    {
        $this->pdo->put('ACTIVE12', null, '-23 hours');
        $this->pdo->put('EXPIRED1', null, '-25 hours');
        $this->pdo->put('SAVED123', 7, '-30 days');

        self::assertSame('ACTIVE12', $this->repository->findByCode('ACTIVE12')['code']);
        self::assertNull($this->repository->findByCode('EXPIRED1'));
        self::assertSame(7, $this->repository->findByCode('SAVED123')['created_by_user_id']);
    }

    public function testCreatedByUserListsOnlyOwnPersistentRoomsByRecentActivity(): void
    {
        $this->pdo->put('OLDER123', 7, '-30 days');
        $this->pdo->put('NEWER123', 7, '-2 hours');
        $this->pdo->put('OTHER123', 8, '-1 hour');
        $this->pdo->put('GUEST123', null, '-10 minutes');

        $rooms = $this->repository->createdByUser(7);

        self::assertSame(['NEWER123', 'OLDER123'], array_column($rooms, 'code'));
        self::assertSame([7, 7], array_column($rooms, 'created_by_user_id'));
    }

    public function testTouchActivityUsesSixtySecondThrottle(): void
    {
        $this->pdo->put('ROOM1234', null, '-61 seconds');
        $roomId = $this->pdo->rooms['ROOM1234']['id'];

        self::assertTrue($this->repository->touchActivity($roomId));
        $firstActivity = $this->pdo->rooms['ROOM1234']['last_activity_at'];
        self::assertFalse($this->repository->touchActivity($roomId));
        self::assertSame($firstActivity, $this->pdo->rooms['ROOM1234']['last_activity_at']);
        self::assertStringContainsString('INTERVAL 60 SECOND', $this->pdo->lastQuery);
    }

    public function testCleanupDeletesOnlyExpiredTemporaryRooms(): void
    {
        $this->pdo->put('EXPIRED1', null, '-25 hours');
        $this->pdo->put('ACTIVE12', null, '-23 hours');
        $this->pdo->put('SAVED123', 7, '-30 days');

        self::assertSame(1, $this->repository->deleteExpiredTemporaryRooms());
        self::assertArrayNotHasKey('EXPIRED1', $this->pdo->rooms);
        self::assertArrayHasKey('ACTIVE12', $this->pdo->rooms);
        self::assertArrayHasKey('SAVED123', $this->pdo->rooms);
    }

    public function testTargetedCleanupCannotDeleteActiveOrPersistentRoom(): void
    {
        $this->pdo->put('EXPIRED1', null, '-25 hours');
        $this->pdo->put('ACTIVE12', null, '-23 hours');
        $this->pdo->put('SAVED123', 7, '-30 days');

        self::assertTrue($this->repository->deleteExpiredTemporaryRoomByCode('EXPIRED1'));
        self::assertFalse($this->repository->deleteExpiredTemporaryRoomByCode('ACTIVE12'));
        self::assertFalse($this->repository->deleteExpiredTemporaryRoomByCode('SAVED123'));
    }

    public function testMigrationDefinesCreatorForeignKeyActivityIndexAndSetNull(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__) . '/database/migrations/2026_09_29_000008_add_room_persistence.sql',
        );

        self::assertStringContainsString('created_by_user_id BIGINT UNSIGNED NULL', $migration);
        self::assertStringContainsString('last_activity_at TIMESTAMP(3) NOT NULL', $migration);
        self::assertStringContainsString('(created_by_user_id, last_activity_at)', $migration);
        self::assertStringContainsString('REFERENCES users (id) ON DELETE SET NULL', $migration);
        self::assertStringContainsString('SET last_activity_at = CURRENT_TIMESTAMP(3)', $migration);
        self::assertStringContainsString('ON DELETE CASCADE', file_get_contents(
            dirname(__DIR__) . '/database/migrations/2026_09_24_000002_create_room_participants.sql',
        ));
        self::assertStringContainsString('ON DELETE CASCADE', file_get_contents(
            dirname(__DIR__) . '/database/migrations/2026_09_25_000004_extract_room_transmission.sql',
        ));
    }
}

final class RoomPersistencePdo extends PDO
{
    private const NOW = '2026-09-29 12:00:00.000';

    /** @var array<string, array<string, mixed>> */
    public array $rooms = [];
    public string $lastQuery = '';
    private int $nextId = 1;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastQuery = $query;
        return new RoomPersistenceStatement($this, $query);
    }

    public function put(string $code, ?int $creatorUserId, string $relativeActivity): void
    {
        $activity = (new \DateTimeImmutable(self::NOW))->modify($relativeActivity);
        $this->rooms[$code] = [
            'id' => $this->nextId++,
            'code' => $code,
            'created_by_user_id' => $creatorUserId,
            'last_activity_at' => $activity->format('Y-m-d H:i:s.v'),
            'created_at' => self::NOW,
        ];
    }

    public function executeStatement(string $query, array $params): int
    {
        if (str_starts_with($query, 'INSERT INTO rooms')) {
            $code = (string) $params['code'];
            if (isset($this->rooms[$code])) {
                $exception = new PDOException('Duplicate entry');
                $exception->errorInfo = ['23000', 1062, 'Duplicate entry'];
                throw $exception;
            }
            $this->put($code, $params['created_by_user_id'], 'now');
            return 1;
        }

        if (str_starts_with($query, 'UPDATE rooms SET last_activity_at')) {
            foreach ($this->rooms as &$room) {
                if ($room['id'] === (int) $params['id'] && $this->ageSeconds($room) > 60) {
                    $room['last_activity_at'] = self::NOW;
                    return 1;
                }
            }
            return 0;
        }

        if (str_starts_with($query, 'DELETE FROM rooms')) {
            $deleted = 0;
            foreach ($this->rooms as $code => $room) {
                if (isset($params['code']) && $params['code'] !== $code) {
                    continue;
                }
                if ($room['created_by_user_id'] === null && $this->ageSeconds($room) > 86_400) {
                    unset($this->rooms[$code]);
                    ++$deleted;
                }
            }
            return $deleted;
        }

        return 0;
    }

    public function fetchRoom(string $code): array|false
    {
        $room = $this->rooms[$code] ?? null;
        if ($room === null) {
            return false;
        }
        if ($room['created_by_user_id'] === null && $this->ageSeconds($room) > 86_400) {
            return false;
        }
        return $room;
    }

    /** @return list<array<string, mixed>> */
    public function createdRoomsForUser(int $userId): array
    {
        $rooms = array_values(array_filter(
            $this->rooms,
            static fn (array $room): bool => $room['created_by_user_id'] === $userId,
        ));
        usort($rooms, static fn (array $left, array $right): int => [
            $right['last_activity_at'],
            $right['id'],
        ] <=> [
            $left['last_activity_at'],
            $left['id'],
        ]);

        return $rooms;
    }

    private function ageSeconds(array $room): int
    {
        return (new \DateTimeImmutable($room['last_activity_at']))->diff(
            new \DateTimeImmutable(self::NOW),
        )->days * 86_400
            + (new \DateTimeImmutable($room['last_activity_at']))->diff(new \DateTimeImmutable(self::NOW))->h * 3_600
            + (new \DateTimeImmutable($room['last_activity_at']))->diff(new \DateTimeImmutable(self::NOW))->i * 60
            + (new \DateTimeImmutable($room['last_activity_at']))->diff(new \DateTimeImmutable(self::NOW))->s;
    }
}

final class RoomPersistenceStatement extends PDOStatement
{
    private array $params = [];
    private int $affectedRows = 0;

    public function __construct(
        private readonly RoomPersistencePdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        $this->affectedRows = $this->pdo->executeStatement($this->query, $this->params);
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->pdo->fetchRoom((string) ($this->params['code'] ?? ''));
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if (str_contains($this->query, 'WHERE created_by_user_id = :user_id')) {
            return $this->pdo->createdRoomsForUser((int) $this->params['user_id']);
        }

        return [];
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }
}
