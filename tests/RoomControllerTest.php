<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\RoomController;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Repositories\RoomParticipantRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\RoomCodeGenerator;
use App\Services\RoomParticipantSession;
use App\Services\RoomTransmissionPresenter;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class RoomControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGuestCreatesTemporaryRoomWith303Redirect(): void
    {
        [$controller, $pdo] = $this->controller();

        $response = $controller->store();

        self::assertSame(303, $response->status());
        self::assertMatchesRegularExpression('#^/room/[A-Z2-9]{8}$#', $response->headers()['Location']);
        self::assertNull($pdo->lastCreatorUserId);
        self::assertSame(1, $pdo->cleanupCalls);
    }

    public function testValidAuthenticatedUserCreatesPersistentRoom(): void
    {
        [$controller, $pdo, $auth] = $this->controller(users: [7 => 'Josiel']);
        $auth->login(7);

        $response = $controller->store();

        self::assertSame(303, $response->status());
        self::assertSame(7, $pdo->lastCreatorUserId);
        self::assertSame(7, $auth->userId());
    }

    public function testStaleAuthCreatesTemporaryRoomAndPreservesParticipantIdentity(): void
    {
        [$controller, $pdo, $auth, $participants] = $this->controller();
        $identity = $participants->remember('OLDROOM1', 'Convidado');
        $auth->login(999);

        $response = $controller->store();

        self::assertSame(303, $response->status());
        self::assertNull($pdo->lastCreatorUserId);
        self::assertNull($auth->userId());
        self::assertSame($identity, $participants->identityFor('OLDROOM1'));
    }

    public function testInvalidCsrfDoesNotCreateOrCleanupRoom(): void
    {
        [$controller, $pdo] = $this->controller(validCsrf: false);

        self::assertSame(419, $controller->store()->status());
        self::assertSame(0, $pdo->roomInsertAttempts);
        self::assertSame(0, $pdo->cleanupCalls);
    }

    public function testCodeCollisionRetriesAndKeepsCreator(): void
    {
        [$controller, $pdo, $auth] = $this->controller(users: [7 => 'Josiel']);
        $auth->login(7);
        $pdo->duplicateRoomInsertsRemaining = 1;

        $response = $controller->store();

        self::assertSame(303, $response->status());
        self::assertSame(2, $pdo->roomInsertAttempts);
        self::assertSame(7, $pdo->lastCreatorUserId);
    }

    /** @return array{RoomController, RoomControllerPdo, AuthSession, RoomParticipantSession} */
    private function controller(bool $validCsrf = true, array $users = []): array
    {
        $pdo = new RoomControllerPdo($users);
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $session = new Session(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $auth = new AuthSession($session);
        $participantSession = new RoomParticipantSession($session);

        return [new RoomController(
            new Request([], ['_token' => $validCsrf ? $token : 'invalid']),
            new View(dirname(__DIR__) . '/resources/views'),
            $session,
            $csrf,
            new RoomRepository($database),
            new RoomParticipantRepository($database),
            new RoomTransmissionRepository($database),
            $participantSession,
            new RoomTransmissionPresenter(),
            new RoomCodeGenerator(),
            new UserRepository($database),
            $auth,
        ), $pdo, $auth, $participantSession];
    }
}

final class RoomControllerPdo extends PDO
{
    public ?int $lastCreatorUserId = null;
    public int $roomInsertAttempts = 0;
    public int $duplicateRoomInsertsRemaining = 0;
    public int $cleanupCalls = 0;

    /** @param array<int, string> $users */
    public function __construct(public array $users)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RoomControllerStatement($this, $query);
    }
}

final class RoomControllerStatement extends PDOStatement
{
    private array $params = [];
    private int $affectedRows = 0;

    public function __construct(
        private readonly RoomControllerPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_starts_with($this->query, 'INSERT INTO rooms')) {
            ++$this->pdo->roomInsertAttempts;
            if ($this->pdo->duplicateRoomInsertsRemaining > 0) {
                --$this->pdo->duplicateRoomInsertsRemaining;
                $exception = new PDOException('Duplicate room code');
                $exception->errorInfo = ['23000', 1062, 'Duplicate room code'];
                throw $exception;
            }
            $this->pdo->lastCreatorUserId = $this->params['created_by_user_id'];
            $this->affectedRows = 1;
        } elseif (str_starts_with($this->query, 'DELETE FROM rooms')) {
            ++$this->pdo->cleanupCalls;
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM users')) {
            $id = (int) ($this->params['id'] ?? 0);
            if (!isset($this->pdo->users[$id])) {
                return false;
            }
            return [
                'id' => $id,
                'display_name' => $this->pdo->users[$id],
                'email' => 'user' . $id . '@example.com',
                'created_at' => '2026-09-29 12:00:00.000',
                'updated_at' => '2026-09-29 12:00:00.000',
            ];
        }
        return false;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }
}
