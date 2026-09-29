<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Repositories\UserRoomRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class UserRoomRepositoryTest extends TestCase
{
    private UserRoomPdo $pdo;
    private UserRoomRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new UserRoomPdo();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $this->pdo);
        $this->repository = new UserRoomRepository($database);
    }

    public function testFirstParticipationAndUpsertPreserveFirstAndUpdateLastWithoutDuplicates(): void
    {
        $this->repository->recordParticipation(7, 12);
        $first = $this->pdo->userRooms['7:12'];
        $this->repository->recordParticipation(7, 12);
        $updated = $this->pdo->userRooms['7:12'];

        self::assertCount(1, $this->pdo->userRooms);
        self::assertSame($first['first_joined_at'], $updated['first_joined_at']);
        self::assertNotSame($first['last_joined_at'], $updated['last_joined_at']);
        self::assertStringContainsString(
            'ON DUPLICATE KEY UPDATE last_joined_at = CURRENT_TIMESTAMP(3)',
            $this->pdo->lastQuery,
        );
        self::assertStringNotContainsString('first_joined_at =', $this->pdo->lastQuery);
    }

    public function testParticipatedListExcludesOwnAndExpiredButIncludesActiveTemporaryAndOldPersistent(): void
    {
        $this->pdo->putRoom(11, 'ACTIVE12', null, '-2 hours');
        $this->pdo->putRoom(12, 'EXPIRED1', null, '-25 hours');
        $this->pdo->putRoom(13, 'OWNROOM1', 7, '-1 hour');
        $this->pdo->putRoom(14, 'SAVED123', 8, '-30 days');
        foreach ([11, 12, 13, 14] as $roomId) {
            $this->repository->recordParticipation(7, $roomId);
        }

        $rooms = $this->repository->participatedByUser(7);

        self::assertSame(['SAVED123', 'ACTIVE12'], array_column($rooms, 'code'));
        self::assertNotContains('OWNROOM1', array_column($rooms, 'code'));
        self::assertNotContains('EXPIRED1', array_column($rooms, 'code'));
        self::assertArrayHasKey('first_joined_at', $rooms[0]);
        self::assertArrayHasKey('last_joined_at', $rooms[0]);
    }

    public function testParticipationHistoryIsScopedToCurrentUser(): void
    {
        $this->pdo->putRoom(11, 'ROOM1234', null, '-1 hour');
        $this->repository->recordParticipation(7, 11);
        $this->repository->recordParticipation(8, 11);

        self::assertCount(1, $this->repository->participatedByUser(7));
        self::assertCount(1, $this->repository->participatedByUser(8));
    }

    public function testMigrationDefinesCompositeKeyRecentIndexAndCascadeForeignKeysWithoutOwnership(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__) . '/database/migrations/2026_09_29_000009_create_user_rooms.sql',
        );

        self::assertStringContainsString('CREATE TABLE user_rooms', $migration);
        self::assertStringContainsString('PRIMARY KEY (user_id, room_id)', $migration);
        self::assertStringContainsString('(user_id, last_joined_at)', $migration);
        self::assertStringContainsString('REFERENCES users (id) ON DELETE CASCADE', $migration);
        self::assertStringContainsString('REFERENCES rooms (id) ON DELETE CASCADE', $migration);
        self::assertStringNotContainsString('is_owner', $migration);
        self::assertStringNotContainsString('permission', $migration);
        self::assertStringNotContainsString('role', $migration);
    }
}

final class UserRoomPdo extends PDO
{
    private const NOW = '2026-09-29 12:00:00.000';

    /** @var array<int, array<string, mixed>> */
    public array $rooms = [];
    /** @var array<string, array<string, mixed>> */
    public array $userRooms = [];
    public string $lastQuery = '';
    private int $clock = 0;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastQuery = $query;
        return new UserRoomStatement($this, $query);
    }

    public function record(int $userId, int $roomId): void
    {
        $key = $userId . ':' . $roomId;
        $timestamp = (new \DateTimeImmutable(self::NOW))
            ->modify('+' . $this->clock++ . ' minutes')
            ->format('Y-m-d H:i:s.v');
        if (!isset($this->userRooms[$key])) {
            $this->userRooms[$key] = [
                'user_id' => $userId,
                'room_id' => $roomId,
                'first_joined_at' => $timestamp,
                'last_joined_at' => $timestamp,
            ];
            return;
        }
        $this->userRooms[$key]['last_joined_at'] = $timestamp;
    }

    public function putRoom(int $id, string $code, ?int $creatorUserId, string $relativeActivity): void
    {
        $this->rooms[$id] = [
            'id' => $id,
            'code' => $code,
            'created_by_user_id' => $creatorUserId,
            'created_at' => self::NOW,
            'last_activity_at' => (new \DateTimeImmutable(self::NOW))
                ->modify($relativeActivity)
                ->format('Y-m-d H:i:s.v'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function participated(int $userId): array
    {
        $cutoff = (new \DateTimeImmutable(self::NOW))->modify('-24 hours');
        $rows = [];
        foreach ($this->userRooms as $participation) {
            if ($participation['user_id'] !== $userId) {
                continue;
            }
            $room = $this->rooms[$participation['room_id']] ?? null;
            if ($room === null || $room['created_by_user_id'] === $userId) {
                continue;
            }
            if ($room['created_by_user_id'] === null
                && new \DateTimeImmutable($room['last_activity_at']) < $cutoff) {
                continue;
            }
            $rows[] = [...$room, ...$participation];
        }
        usort($rows, static fn (array $left, array $right): int => [
            $right['last_joined_at'],
            $right['id'],
        ] <=> [
            $left['last_joined_at'],
            $left['id'],
        ]);

        return $rows;
    }
}

final class UserRoomStatement extends PDOStatement
{
    private array $params = [];

    public function __construct(
        private readonly UserRoomPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_starts_with($this->query, 'INSERT INTO user_rooms')) {
            $this->pdo->record((int) $this->params['user_id'], (int) $this->params['room_id']);
        }
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->pdo->participated((int) $this->params['user_id']);
    }
}
