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
use App\Repositories\UserRepository;
use App\Repositories\UserRoomRepository;
use App\Services\AuthSession;
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

    public function testValidJoinTouchesRoomActivity(): void
    {
        [$controller, $pdo] = $this->controller(null, extraBody: ['display_name' => 'Pedro']);

        $response = $controller->join('ROOM1234');

        self::assertSame(303, $response->status());
        self::assertSame(1, $pdo->roomActivityTouches);
        self::assertSame('Pedro', $pdo->lastParticipantName);
        self::assertNull($pdo->lastParticipantUserId);
    }

    public function testAuthenticatedJoinIgnoresForgedNicknameAndUsesAccountIdentity(): void
    {
        [$controller, $pdo, $participants] = $this->controller(
            null,
            joined: false,
            extraBody: ['display_name' => 'Forged'],
            authUserId: 7,
            users: [7 => 'Josiel'],
        );

        self::assertSame(303, $controller->join('ROOM1234')->status());
        self::assertSame('Josiel', $pdo->lastParticipantName);
        self::assertSame(7, $pdo->lastParticipantUserId);
        self::assertSame(7, $participants->identityFor('ROOM1234')['user_id']);
    }

    public function testGuestIdentityIsBoundToLoggedAccountDuringPresence(): void
    {
        [$controller, $pdo, $participants] = $this->controller(
            null,
            authUserId: 7,
            users: [7 => 'Josiel'],
        );
        $before = $participants->identityFor('ROOM1234');

        self::assertSame(200, $controller->presence('ROOM1234')->status());
        $after = $participants->identityFor('ROOM1234');
        self::assertSame($before['participant_key'], $after['participant_key']);
        self::assertSame('Josiel', $after['display_name']);
        self::assertSame(7, $after['user_id']);
        self::assertSame(7, $pdo->lastParticipantUserId);
    }

    public function testInvalidJoinAndInvalidCsrfDoNotTouchRoomActivity(): void
    {
        [$invalidJoin, $invalidJoinPdo] = $this->controller(null, extraBody: ['display_name' => '']);
        [$invalidCsrf, $invalidCsrfPdo] = $this->controller(
            null,
            validCsrf: false,
            extraBody: ['display_name' => 'Pedro'],
        );

        self::assertSame(303, $invalidJoin->join('ROOM1234')->status());
        self::assertSame(0, $invalidJoinPdo->roomActivityTouches);
        self::assertSame(419, $invalidCsrf->join('ROOM1234')->status());
        self::assertSame(0, $invalidCsrfPdo->roomActivityTouches);
    }

    public function testValidPresenceTouchesRoomActivity(): void
    {
        [$controller, $pdo] = $this->controller('0123456789abcdef0123456789abcdef');

        self::assertSame(200, $controller->presence('ROOM1234')->status());
        self::assertSame(1, $pdo->roomActivityTouches);
    }

    public function testInvalidCsrfPresenceDoesNotTouchRoomActivity(): void
    {
        [$controller, $pdo] = $this->controller(null, validCsrf: false);

        self::assertSame(419, $controller->presence('ROOM1234')->status());
        self::assertSame(0, $pdo->roomActivityTouches);
    }

    public function testPresenceWithoutIdentityOrWithExpiredRoomDoesNotTouchActivity(): void
    {
        [$withoutIdentity, $identityPdo] = $this->controller(null, joined: false);

        self::assertSame(403, $withoutIdentity->presence('ROOM1234')->status());
        self::assertSame(0, $identityPdo->roomActivityTouches);

        [$expiredRoom, $expiredPdo] = $this->controller(null, roomExists: false);
        self::assertSame(404, $expiredRoom->presence('ROOM1234')->status());
        self::assertSame(0, $expiredPdo->roomActivityTouches);
    }

    public function testPresenceRejectsInvalidPlayerInstanceWithSpecific422(): void
    {
        [$controller, $pdo] = $this->controller('NOT-LOWERCASE-HEX');

        $response = $controller->presence('ROOM1234');

        self::assertSame(422, $response->status());
        self::assertSame(['error' => 'invalid_player_instance'], json_decode($response->body(), true));
        self::assertNull($pdo->registeredInstanceHash);
        self::assertSame(0, $pdo->roomActivityTouches);
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
        array $extraBody = [],
        ?int $authUserId = null,
        array $users = [],
    ): array {
        $pdo = new PresencePdo($users);
        $pdo->roomExists = $roomExists;
        $pdo->currentInstanceHash = $currentInstanceHash;
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $session = new Session(false);
        $csrf = new Csrf($session);
        $csrfToken = $csrf->token();
        $participantSession = new RoomParticipantSession($session);
        $auth = new AuthSession($session);
        if ($authUserId !== null) {
            $auth->login($authUserId);
        }
        if ($joined) {
            $participantSession->remember('ROOM1234', 'Pedro');
        }
        $playback = new RoomTransmissionPlayback();

        return [new RoomParticipantController(
            new Request([], [...[
                '_token' => $validCsrf ? $csrfToken : 'invalid-token',
                'player_instance_id' => $playerInstanceId,
            ], ...$extraBody]),
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
            new UserRepository($database),
            new UserRoomRepository($database),
            $auth,
        ), $pdo, $participantSession, $auth];
    }
}

final class PresencePdo extends PDO
{
    public ?string $registeredInstanceHash = null;
    public ?string $currentInstanceHash = null;
    public ?string $lastLeaveInstanceHash = null;
    public bool $roomExists = true;
    public int $roomActivityTouches = 0;
    public ?string $lastParticipantName = null;
    public ?int $lastParticipantUserId = null;

    /** @param array<int, string> $users */
    public function __construct(public array $users = [])
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
        if (str_starts_with($this->query, 'UPDATE rooms SET last_activity_at')) {
            ++$this->pdo->roomActivityTouches;
            $this->affectedRows = 1;
            return true;
        }
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
        if (str_starts_with($this->query, 'INSERT INTO room_participants')) {
            $this->pdo->lastParticipantName = (string) $this->params['display_name'];
            $this->pdo->lastParticipantUserId = isset($this->params['user_id'])
                ? (int) $this->params['user_id']
                : null;
            $this->affectedRows = 1;
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
        if (str_contains($this->query, 'FROM users')) {
            $id = (int) ($this->params['id'] ?? 0);
            if (!isset($this->pdo->users[$id])) {
                return false;
            }
            return [
                'id' => $id,
                'display_name' => $this->pdo->users[$id],
                'email' => 'user' . $id . '@example.test',
                'created_at' => '2026-09-30 10:00:00.000',
                'updated_at' => '2026-09-30 10:00:00.000',
            ];
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
