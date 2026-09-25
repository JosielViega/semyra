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
        $connection = new \ReflectionProperty($database, 'connection');
        $connection->setValue($database, $this->pdo);
        $this->repository = new RoomTransmissionRepository($database);
    }

    public function testCurrentOwnerRemovesTransmission(): void
    {
        $this->pdo->put(7, 'owner-a');

        self::assertTrue($this->repository->end(7, 'owner-a'));
        self::assertFalse($this->pdo->has(7));
        self::assertStringContainsString(
            'AND owner_participant_key_hash = :owner_participant_key_hash',
            $this->pdo->lastQuery,
        );
    }

    public function testDifferentOwnerDoesNotRemoveTransmission(): void
    {
        $this->pdo->put(7, 'owner-b');

        self::assertFalse($this->repository->end(7, 'owner-a'));
        self::assertTrue($this->pdo->has(7));
        self::assertSame('owner-b', $this->pdo->ownerFor(7));
    }

    public function testOldOwnerCannotRemoveReplacementTransmission(): void
    {
        $this->pdo->put(7, 'owner-a');
        $this->pdo->put(7, 'owner-b');

        self::assertFalse($this->repository->end(7, 'owner-a'));
        self::assertSame('owner-b', $this->pdo->ownerFor(7));
    }
}

final class TransmissionPdo extends PDO
{
    /** @var array<int, string> */
    private array $owners = [];
    public string $lastQuery = '';

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastQuery = $query;

        return new TransmissionDeleteStatement($this, $query);
    }

    public function put(int $roomId, string $ownerHash): void
    {
        $this->owners[$roomId] = $ownerHash;
    }

    public function has(int $roomId): bool
    {
        return isset($this->owners[$roomId]);
    }

    public function ownerFor(int $roomId): ?string
    {
        return $this->owners[$roomId] ?? null;
    }

    public function deleteForOwner(int $roomId, string $ownerHash): int
    {
        if (($this->owners[$roomId] ?? null) !== $ownerHash) {
            return 0;
        }

        unset($this->owners[$roomId]);

        return 1;
    }
}

final class TransmissionDeleteStatement extends PDOStatement
{
    private int $affectedRows = 0;

    public function __construct(
        private readonly TransmissionPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        if (!str_contains($this->query, 'owner_participant_key_hash = :owner_participant_key_hash')) {
            throw new \LogicException('The delete must include the expected owner hash.');
        }

        $this->affectedRows = $this->pdo->deleteForOwner(
            (int) ($params['room_id'] ?? 0),
            (string) ($params['owner_participant_key_hash'] ?? ''),
        );

        return true;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }
}
