<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\MyRoomsController;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\RoomRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserRoomRepository;
use App\Services\AuthSession;
use App\Services\RoomParticipantSession;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class MyRoomsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGuestRedirectsToLoginWithoutQueries(): void
    {
        [$controller, $pdo] = $this->controller();

        $response = $controller->index();

        self::assertSame(303, $response->status());
        self::assertSame('/login', $response->headers()['Location']);
        self::assertSame(0, $pdo->cleanupCalls);
    }

    public function testInvalidAuthLogsOutPreservesParticipantIdentityAndRedirects(): void
    {
        [$controller, , $auth, $participants] = $this->controller();
        $identity = $participants->remember('ROOM1234', 'Guest');
        $auth->login(999);

        $response = $controller->index();

        self::assertSame(303, $response->status());
        self::assertSame('/login', $response->headers()['Location']);
        self::assertNull($auth->userId());
        self::assertSame($identity, $participants->identityFor('ROOM1234'));
    }

    public function testAuthenticatedEmptyPageShowsBothEmptyStatesAndHeaderLink(): void
    {
        [$controller, $pdo, $auth] = $this->controller(userExists: true);
        $auth->login(7);

        $response = $controller->index();

        self::assertSame(200, $response->status());
        self::assertSame(1, $pdo->cleanupCalls);
        self::assertStringContainsString('Criadas por mim', $response->body());
        self::assertStringContainsString('Participei', $response->body());
        self::assertStringContainsString(
            'Você ainda não criou nenhuma sala estando conectado.',
            $response->body(),
        );
        self::assertStringContainsString(
            'Você ainda não participou de outras salas com esta conta.',
            $response->body(),
        );
        self::assertStringContainsString('href="/rooms">Minhas salas</a>', $response->body());
    }

    public function testListsCreatedAndParticipatedRoomsWithDatesLinksAndEscaping(): void
    {
        [$controller, $pdo, $auth] = $this->controller(userExists: true);
        $pdo->createdRooms = [$this->room('OWNR1234', 7)];
        $pdo->participatedRooms = [[
            ...$this->room('<script>', 8),
            'first_joined_at' => '2026-09-28 11:00:00.000',
            'last_joined_at' => '2026-09-29 16:30:00.000',
        ]];
        $auth->login(7);

        $html = $controller->index()->body();

        self::assertSame(1, substr_count($html, 'Sala OWNR1234'));
        self::assertStringContainsString('href="/room/OWNR1234"', $html);
        self::assertStringContainsString('29/09/2026 16:30', $html);
        self::assertStringContainsString('Sala &lt;script&gt;', $html);
        self::assertStringNotContainsString('Sala <script>', $html);
    }

    /** @return array{MyRoomsController, MyRoomsPdo, AuthSession, RoomParticipantSession} */
    private function controller(bool $userExists = false): array
    {
        $pdo = new MyRoomsPdo($userExists);
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $session = new Session(false);
        $auth = new AuthSession($session);
        $participants = new RoomParticipantSession($session);

        return [new MyRoomsController(
            new View(dirname(__DIR__) . '/resources/views'),
            $session,
            new Csrf($session),
            new RoomRepository($database),
            new UserRoomRepository($database),
            new UserRepository($database),
            $auth,
        ), $pdo, $auth, $participants];
    }

    private function room(string $code, ?int $creator): array
    {
        return [
            'id' => 12,
            'code' => $code,
            'created_by_user_id' => $creator,
            'created_at' => '2026-09-29 10:15:00.000',
            'last_activity_at' => '2026-09-29 16:30:00.000',
        ];
    }
}

final class MyRoomsPdo extends PDO
{
    /** @var list<array<string, mixed>> */
    public array $createdRooms = [];
    /** @var list<array<string, mixed>> */
    public array $participatedRooms = [];
    public int $cleanupCalls = 0;

    public function __construct(public bool $userExists)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new MyRoomsStatement($this, $query);
    }
}

final class MyRoomsStatement extends PDOStatement
{
    private array $params = [];

    public function __construct(
        private readonly MyRoomsPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_starts_with($this->query, 'DELETE FROM rooms')) {
            ++$this->pdo->cleanupCalls;
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM users') && $this->pdo->userExists) {
            return [
                'id' => 7,
                'display_name' => 'Josiel',
                'email' => 'josiel@example.com',
                'created_at' => '2026-09-29 10:00:00.000',
                'updated_at' => '2026-09-29 10:00:00.000',
            ];
        }
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if (str_contains($this->query, 'FROM user_rooms')) {
            return $this->pdo->participatedRooms;
        }
        if (str_contains($this->query, 'FROM rooms WHERE created_by_user_id')) {
            return $this->pdo->createdRooms;
        }
        return [];
    }

    public function rowCount(): int
    {
        return 0;
    }
}
