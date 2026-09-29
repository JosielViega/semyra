<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\RoomParticipantController;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Repositories\RoomParticipantRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Services\RoomParticipantSession;
use App\Services\RoomPlaybackTelemetry;
use App\Services\RoomTransmissionPlayback;
use App\Services\RoomTransmissionPresenter;
use App\Validation\Validator;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class RoomParticipantControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testPresenceRejectsInvalidPlayerInstanceWithSpecific422(): void
    {
        [$controller, $pdo] = $this->controller('NOT-LOWERCASE-HEX');

        $response = $controller->presence('ROOM1234');

        self::assertSame(422, $response->status());
        self::assertSame(['error' => 'invalid_player_instance'], json_decode($response->body(), true));
        self::assertNull($pdo->registeredInstanceHash);
    }

    public function testPresenceHashesValidPlayerInstanceBeforeRegistration(): void
    {
        $nonce = '0123456789abcdef0123456789abcdef';
        [$controller, $pdo] = $this->controller($nonce);

        $response = $controller->presence('ROOM1234');

        self::assertSame(200, $response->status());
        self::assertSame(hash('sha256', $nonce), $pdo->registeredInstanceHash);
        self::assertNotSame($nonce, $pdo->registeredInstanceHash);
    }

    public function testLeaveCurrentInstanceSucceedsWithoutForgettingSessionIdentity(): void
    {
        $nonce = '0123456789abcdef0123456789abcdef';
        $instanceHash = hash('sha256', $nonce);
        [$controller, $pdo, $participantSession] = $this->controller(
            $nonce,
            currentInstanceHash: $instanceHash,
        );

        $response = $controller->leave('ROOM1234');

        self::assertSame(200, $response->status());
        self::assertSame(['left' => true], json_decode($response->body(), true));
        self::assertSame($instanceHash, $pdo->lastLeaveInstanceHash);
        self::assertSame($instanceHash, $pdo->currentInstanceHash);
        self::assertNotNull($participantSession->identityFor('ROOM1234'));
    }

    public function testStaleLeaveDoesNotRemoveCurrentInstance(): void
    {
        $oldNonce = '0123456789abcdef0123456789abcdef';
        $currentHash = hash('sha256', 'fedcba9876543210fedcba9876543210');
        [$controller, $pdo] = $this->controller($oldNonce, currentInstanceHash: $currentHash);

        $response = $controller->leave('ROOM1234');

        self::assertSame(200, $response->status());
        self::assertSame(['left' => false], json_decode($response->body(), true));
        self::assertSame($currentHash, $pdo->currentInstanceHash);
    }

    public function testLeaveRejectsInvalidCsrf(): void
    {
        [$controller] = $this->controller('0123456789abcdef0123456789abcdef', validCsrf: false);

        $response = $controller->leave('ROOM1234');

        self::assertSame(419, $response->status());
        self::assertSame(['error' => 'invalid_csrf'], json_decode($response->body(), true));
    }

    public function testLeaveRejectsMissingRoom(): void
    {
        [$controller] = $this->controller('0123456789abcdef0123456789abcdef', roomExists: false);

        $response = $controller->leave('ROOM1234');

        self::assertSame(404, $response->status());
        self::assertSame(['error' => 'room_not_found'], json_decode($response->body(), true));
    }

    public function testLeaveRequiresJoinedIdentity(): void
    {
        [$controller] = $this->controller('0123456789abcdef0123456789abcdef', joined: false);

        $response = $controller->leave('ROOM1234');

        self::assertSame(403, $response->status());
        self::assertSame(['error' => 'join_required'], json_decode($response->body(), true));
    }

    public function testLeaveRejectsMissingOrInvalidPlayerInstance(): void
    {
        [$missingController] = $this->controller(null);
        [$invalidController] = $this->controller('INVALID');

        foreach ([$missingController->leave('ROOM1234'), $invalidController->leave('ROOM1234')] as $response) {
            self::assertSame(422, $response->status());
            self::assertSame(['error' => 'invalid_player_instance'], json_decode($response->body(), true));
        }
    }

    /** @return array{RoomParticipantController, PresencePdo, RoomParticipantSession} */
    private function controller(
        mixed $playerInstanceId,
        bool $validCsrf = true,
        bool $roomExists = true,
        bool $joined = true,
        ?string $currentInstanceHash = null,
    ): array {
        $pdo = new PresencePdo();
        $pdo->roomExists = $roomExists;
        $pdo->currentInstanceHash = $currentInstanceHash;
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $session = new Session(false);
        $csrf = new Csrf($session);
        $csrfToken = $csrf->token();
        $participantSession = new RoomParticipantSession($session);
        if ($joined) {
            $participantSession->remember('ROOM1234', 'Pedro');
        }
        $playback = new RoomTransmissionPlayback();

        return [new RoomParticipantController(
            new Request([], [
                '_token' => $validCsrf ? $csrfToken : 'invalid-token',
                'player_instance_id' => $playerInstanceId,
            ]),
            new View(dirname(__DIR__) . '/resources/views'),
            $session,
            $csrf,
            new Validator(),
            new RoomRepository($database),
            new RoomParticipantRepository($database),
            new RoomTransmissionRepository($database),
            $participantSession,
            new RoomPlaybackTelemetry(),
            new RoomTransmissionPresenter($playback),
            $playback,
        ), $pdo, $participantSession];
    }
}

final class PresencePdo extends PDO
{
    public ?string $registeredInstanceHash = null;
    public ?string $currentInstanceHash = null;
    public ?string $lastLeaveInstanceHash = null;
    public bool $roomExists = true;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PresenceStatement($this, $query);
    }
}

final class PresenceStatement extends PDOStatement
{
    private array $params = [];
    private int $affectedRows = 0;

    public function __construct(
        private readonly PresencePdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (array_key_exists('player_instance_key_hash', $this->params)) {
            $instanceHash = (string) $this->params['player_instance_key_hash'];
            if (str_starts_with($this->query, 'UPDATE room_participants SET')) {
                $this->pdo->lastLeaveInstanceHash = $instanceHash;
                if ($this->pdo->currentInstanceHash === null
                    || $this->pdo->currentInstanceHash === $instanceHash) {
                    $this->affectedRows = 1;
                }
            } else {
                $this->pdo->registeredInstanceHash = $instanceHash;
                $this->pdo->currentInstanceHash = $instanceHash;
                $this->affectedRows = 1;
            }
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM rooms')) {
            return $this->pdo->roomExists
                ? ['id' => 7, 'code' => 'ROOM1234', 'created_at' => '2026-09-29 12:00:00']
                : false;
        }
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }
}
