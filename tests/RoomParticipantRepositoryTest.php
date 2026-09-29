<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Repositories\RoomParticipantRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class RoomParticipantRepositoryTest extends TestCase
{
    private ParticipantPdo $pdo;
    private RoomParticipantRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new ParticipantPdo();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $this->pdo);
        $this->repository = new RoomParticipantRepository($database);
    }

    public function testRegisterPlayerInstanceIsIdempotentAndPersistsOnlyProvidedHash(): void
    {
        $nonce = '0123456789abcdef0123456789abcdef';
        $instanceHash = hash('sha256', $nonce);

        $this->repository->registerPlayerInstance(7, 'participant-hash', 'Pedro', $instanceHash);
        $this->repository->registerPlayerInstance(7, 'participant-hash', 'Pedro', $instanceHash);

        self::assertSame($instanceHash, $this->pdo->instanceHash());
        self::assertNotContains($nonce, $this->pdo->allParameterValues());
        self::assertSame(2, $this->pdo->registrationCount);
    }

    public function testNewNonceHashReplacesInstanceAndNormalTouchDoesNotClearIt(): void
    {
        $firstHash = hash('sha256', '0123456789abcdef0123456789abcdef');
        $secondHash = hash('sha256', 'fedcba9876543210fedcba9876543210');
        $this->repository->registerPlayerInstance(7, 'participant-hash', 'Pedro', $firstHash);
        $this->repository->registerPlayerInstance(7, 'participant-hash', 'Pedro', $secondHash);
        $this->repository->touch(7, 'participant-hash', 'Pedro', [
            'state' => 1,
            'position_ms' => 1_000,
            'duration_ms' => 10_000,
        ]);

        self::assertSame($secondHash, $this->pdo->instanceHash());
        self::assertStringNotContainsString('player_instance_key_hash', $this->pdo->lastQuery);
    }

    public function testActiveParticipantsSelectIncludesInternalInstanceHash(): void
    {
        $this->repository->activeForRoom(7);

        self::assertStringContainsString('SELECT participant_key_hash, player_instance_key_hash', $this->pdo->lastQuery);
    }
}

final class ParticipantPdo extends PDO
{
    public string $lastQuery = '';
    public int $registrationCount = 0;
    private ?string $playerInstanceKeyHash = null;
    /** @var list<array<string, mixed>> */
    private array $executedParams = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastQuery = $query;
        return new ParticipantStatement($this, $query);
    }

    public function executeStatement(string $query, array $params): void
    {
        $this->executedParams[] = $params;
        if (array_key_exists('player_instance_key_hash', $params)) {
            ++$this->registrationCount;
            $this->playerInstanceKeyHash = (string) $params['player_instance_key_hash'];
        }
    }

    public function instanceHash(): ?string
    {
        return $this->playerInstanceKeyHash;
    }

    /** @return list<mixed> */
    public function allParameterValues(): array
    {
        $values = [];
        foreach ($this->executedParams as $params) {
            array_push($values, ...array_values($params));
        }
        return $values;
    }
}

final class ParticipantStatement extends PDOStatement
{
    public function __construct(
        private readonly ParticipantPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->executeStatement($this->query, $params ?? []);
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}
