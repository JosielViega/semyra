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

        self::assertStringContainsString(
            'SELECT participant.user_id, participant.participant_key_hash, participant.player_instance_key_hash',
            $this->pdo->lastQuery,
        );
        self::assertStringContainsString('participant.user_id IS NULL OR NOT EXISTS', $this->pdo->lastQuery);
    }

    public function testAuthenticatedTouchesPersistUserAndGuestTouchesPersistNull(): void
    {
        $this->repository->touch(7, 'account-hash', 'Josiel', null, 42);
        self::assertSame(42, $this->pdo->lastParams()['user_id']);
        self::assertStringContainsString('user_id = IF(user_id IS NULL', $this->pdo->lastQuery);

        $this->repository->touch(7, 'guest-hash', 'Guest');
        self::assertNull($this->pdo->lastParams()['user_id']);
    }

    public function testCurrentInstanceLeaveMakesParticipantInactiveAndClearsTelemetry(): void
    {
        $instanceHash = hash('sha256', 'current-instance');
        $this->pdo->put($instanceHash, true);

        self::assertTrue($this->repository->leavePlayerInstance(7, 'participant-hash', $instanceHash));
        self::assertSame([], $this->repository->activeForRoom(7));
        self::assertSame($instanceHash, $this->pdo->instanceHash());
        self::assertFalse($this->pdo->hasTelemetry());
    }

    public function testWrongOrStaleInstanceCannotRemoveCurrentParticipant(): void
    {
        $oldHash = hash('sha256', 'old-instance');
        $currentHash = hash('sha256', 'current-instance');
        $this->pdo->put($currentHash, true);

        self::assertFalse($this->repository->leavePlayerInstance(7, 'participant-hash', $oldHash));
        self::assertCount(1, $this->repository->activeForRoom(7));
        self::assertSame($currentHash, $this->pdo->instanceHash());
        self::assertTrue($this->pdo->hasTelemetry());
    }

    public function testNewInstanceSurvivesDelayedLeaveFromPreviousReload(): void
    {
        $oldHash = hash('sha256', 'old-instance');
        $currentHash = hash('sha256', 'current-instance');
        $this->pdo->put($oldHash, true);
        $this->repository->registerPlayerInstance(7, 'participant-hash', 'Pedro', $currentHash);

        self::assertFalse($this->repository->leavePlayerInstance(7, 'participant-hash', $oldHash));
        self::assertCount(1, $this->repository->activeForRoom(7));
        self::assertSame($currentHash, $this->pdo->instanceHash());
    }

    public function testNullBootstrapInstanceCanLeaveSafelyBeforeRegistrationCompletes(): void
    {
        $this->pdo->put(null, false);

        self::assertTrue($this->repository->leavePlayerInstance(
            7,
            'participant-hash',
            hash('sha256', 'bootstrap-instance'),
        ));
        self::assertSame([], $this->repository->activeForRoom(7));
    }

    public function testAuthenticatedIdentityMigrationIsAdditiveAndNonUnique(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__) . '/database/migrations/2026_09_30_000010_add_authenticated_room_identity.sql',
        );

        self::assertStringContainsString('ADD COLUMN user_id BIGINT UNSIGNED NULL', $migration);
        self::assertStringContainsString('(room_id, user_id)', $migration);
        self::assertStringContainsString('REFERENCES users (id) ON DELETE SET NULL', $migration);
        self::assertStringContainsString('ADD COLUMN owner_user_id BIGINT UNSIGNED NULL', $migration);
        self::assertStringContainsString('owner_participant_key_hash', file_get_contents(
            dirname(__DIR__) . '/database/migrations/2026_09_25_000004_extract_room_transmission.sql',
        ));
        self::assertStringNotContainsString('UNIQUE', $migration);
    }
}

final class ParticipantPdo extends PDO
{
    public string $lastQuery = '';
    public int $registrationCount = 0;
    private ?string $playerInstanceKeyHash = null;
    private bool $active = false;
    private bool $telemetry = false;
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

    public function executeStatement(string $query, array $params): int
    {
        $this->executedParams[] = $params;
        if (array_key_exists('player_instance_key_hash', $params)) {
            if (str_starts_with($query, 'UPDATE room_participants SET')) {
                if (!$this->active
                    || ($this->playerInstanceKeyHash !== null
                        && $this->playerInstanceKeyHash !== $params['player_instance_key_hash'])) {
                    return 0;
                }
                $this->active = false;
                $this->telemetry = false;
                return 1;
            }
            ++$this->registrationCount;
            $this->playerInstanceKeyHash = (string) $params['player_instance_key_hash'];
            $this->active = true;
            return 1;
        }
        if (str_starts_with($query, 'INSERT INTO room_participants')) {
            $this->active = true;
            $this->telemetry = array_key_exists('player_state', $params);
            return 1;
        }

        return 0;
    }

    public function put(?string $instanceHash, bool $telemetry): void
    {
        $this->playerInstanceKeyHash = $instanceHash;
        $this->active = true;
        $this->telemetry = $telemetry;
    }

    public function instanceHash(): ?string
    {
        return $this->playerInstanceKeyHash;
    }

    public function hasTelemetry(): bool
    {
        return $this->telemetry;
    }

    /** @return list<array<string, mixed>> */
    public function activeRows(): array
    {
        if (!$this->active) {
            return [];
        }

        return [[
            'user_id' => null,
            'participant_key_hash' => 'participant-hash',
            'player_instance_key_hash' => $this->playerInstanceKeyHash,
            'display_name' => 'Pedro',
            'player_state' => $this->telemetry ? 1 : null,
            'player_position_ms' => $this->telemetry ? 1_000 : null,
            'player_duration_ms' => $this->telemetry ? 10_000 : null,
            'player_sample_age_ms' => $this->telemetry ? 100 : null,
        ]];
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

    /** @return array<string, mixed> */
    public function lastParams(): array
    {
        return $this->executedParams[array_key_last($this->executedParams)] ?? [];
    }
}

final class ParticipantStatement extends PDOStatement
{
    private int $affectedRows = 0;

    public function __construct(
        private readonly ParticipantPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->affectedRows = $this->pdo->executeStatement($this->query, $params ?? []);
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->pdo->activeRows();
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }
}
