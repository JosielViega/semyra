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

    /** @return array{RoomParticipantController, PresencePdo} */
    private function controller(mixed $playerInstanceId): array
    {
        $pdo = new PresencePdo();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $session = new Session(false);
        $csrf = new Csrf($session);
        $csrfToken = $csrf->token();
        $participantSession = new RoomParticipantSession($session);
        $participantSession->remember('ROOM1234', 'Pedro');
        $playback = new RoomTransmissionPlayback();

        return [new RoomParticipantController(
            new Request([], ['_token' => $csrfToken, 'player_instance_id' => $playerInstanceId]),
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
        ), $pdo];
    }
}

final class PresencePdo extends PDO
{
    public ?string $registeredInstanceHash = null;

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

    public function __construct(
        private readonly PresencePdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (array_key_exists('player_instance_key_hash', $this->params)) {
            $this->pdo->registeredInstanceHash = (string) $this->params['player_instance_key_hash'];
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM rooms')) {
            return ['id' => 7, 'code' => 'ROOM1234', 'created_at' => '2026-09-29 12:00:00'];
        }
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}
