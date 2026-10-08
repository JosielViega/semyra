<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Repositories\UserRepository;
use App\Controllers\DesktopAccountContextController;
use App\Controllers\DesktopIptvController;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthSession;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class UserRepositoryTest extends TestCase
{
    private UserStorePdo $pdo;
    private UserRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new UserStorePdo();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $this->pdo);
        $this->repository = new UserRepository($database);
    }

    public function testCreateAndFindByIdExposeOnlyPublicData(): void
    {
        $hash = password_hash('secure-password', PASSWORD_DEFAULT);
        $id = $this->repository->create('Josiel', ' Teste@Email.com ', $hash);
        $user = $this->repository->findById($id);

        self::assertSame(1, $id);
        self::assertSame('Josiel', $user['display_name']);
        self::assertSame('teste@email.com', $user['email']);
        self::assertArrayNotHasKey('password_hash', $user);
    }

    public function testFindByEmailReturnsCredentialsForAuthentication(): void
    {
        $hash = password_hash('secure-password', PASSWORD_DEFAULT);
        $this->repository->create('Josiel', 'teste@email.com', $hash);

        $user = $this->repository->findByEmail(' TESTE@EMAIL.COM ');

        self::assertSame('teste@email.com', $user['email']);
        self::assertSame($hash, $user['password_hash']);
        self::assertTrue(password_verify('secure-password', $user['password_hash']));
        self::assertNotSame('secure-password', $this->pdo->rows[1]['password_hash']);
    }

    public function testMissingEmailReturnsNull(): void
    {
        self::assertNull($this->repository->findByEmail('missing@example.com'));
    }

    public function testUniqueEmailTreatsDifferentCaseAsDuplicateWithoutThrowing(): void
    {
        $hash = password_hash('secure-password', PASSWORD_DEFAULT);

        self::assertSame(1, $this->repository->create('Primeiro', 'User@Example.com', $hash));
        self::assertNull($this->repository->create('Segundo', 'user@example.com', $hash));
        self::assertCount(1, $this->pdo->rows);
    }

    public function testMigrationDefinesRequiredUniqueCaseInsensitiveSchema(): void
    {
        $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/2026_09_29_000007_create_users.sql');

        self::assertStringContainsString('CREATE TABLE users', $migration);
        self::assertStringContainsString('display_name VARCHAR(30)', $migration);
        self::assertStringContainsString('email VARCHAR(191)', $migration);
        self::assertStringContainsString('COLLATE ascii_general_ci', $migration);
        self::assertStringContainsString('UNIQUE KEY users_email_unique (email)', $migration);
        self::assertStringNotContainsString('remember_token', $migration);
    }

    public function testDesktopProfileIdsAreStableDistinctAndOpaque(): void
    {
        $hash = password_hash('secure-password', PASSWORD_DEFAULT);
        $first = $this->repository->create('A', 'a@example.com', $hash);
        $second = $this->repository->create('B', 'b@example.com', $hash);

        $firstId = $this->repository->ensureDesktopProfileId($first);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $firstId);
        self::assertSame($firstId, $this->repository->ensureDesktopProfileId($first));
        self::assertNotSame($firstId, $this->repository->ensureDesktopProfileId($second));
        self::assertArrayNotHasKey('desktop_profile_id', $this->repository->findById($first));
    }

    public function testConcurrentAllocationNeverReplacesWinningProfile(): void
    {
        $userId = $this->repository->create('Race', 'race@example.com', password_hash('secure-password', PASSWORD_DEFAULT));
        $winner = str_repeat('d', 64);
        $this->pdo->raceProfileId = $winner;
        self::assertSame($winner, $this->repository->ensureDesktopProfileId($userId));
        self::assertSame($winner, $this->pdo->rows[$userId]['desktop_profile_id']);
    }

    public function testDesktopProfileMigrationIsNullableAsciiAndUnique(): void
    {
        $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/2026_10_07_000016_add_desktop_profile_id_to_users.sql');
        self::assertStringContainsString('desktop_profile_id CHAR(64)', $migration);
        self::assertStringContainsString('COLLATE ascii_bin', $migration);
        self::assertStringContainsString('NULL', $migration);
        self::assertStringContainsString('UNIQUE KEY users_desktop_profile_id_unique', $migration);
    }

    public function testAccountContextRequiresAuthenticationAndReturnsOnlyOpaqueProfile(): void
    {
        $_SESSION = [];
        $session = new Session(false);
        $auth = new AuthSession($session);
        $controller = new DesktopAccountContextController($this->repository, $auth);
        $guest = $controller->show();
        self::assertSame(401, $guest->status());
        self::assertSame('no-store', $guest->headers()['Cache-Control']);

        $userId = $this->repository->create('Josiel', 'josiel@example.com', password_hash('secure-password', PASSWORD_DEFAULT));
        $auth->login($userId);
        $response = $controller->show();
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $response->status());
        self::assertSame(['profile_id'], array_keys($body));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $body['profile_id']);
        self::assertStringNotContainsString('josiel', $response->body());
        self::assertSame('no-cache', $response->headers()['Pragma']);
    }

    public function testDesktopIptvPageRequiresAuthenticatedAccount(): void
    {
        $_SESSION = [];
        $session = new Session(false);
        $auth = new AuthSession($session);
        $controller = new DesktopIptvController(
            new View(dirname(__DIR__) . '/resources/views'),
            new Csrf($session),
            $this->repository,
            $auth,
        );

        $guest = $controller->index();
        self::assertSame(303, $guest->status());
        self::assertSame('/login', $guest->headers()['Location']);

        $userId = $this->repository->create('Josiel', 'josiel@example.com', password_hash('secure-password', PASSWORD_DEFAULT));
        $auth->login($userId);
        $authenticated = $controller->index();
        self::assertSame(200, $authenticated->status());
        self::assertStringContainsString('Minha IPTV', $authenticated->body());
    }
}

final class UserStorePdo extends PDO
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 1;
    private int $lastId = 0;
    public ?string $raceProfileId = null;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new UserStoreStatement($this, $query);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->lastId;
    }

    public function insert(array $params): void
    {
        foreach ($this->rows as $row) {
            if (strcasecmp($row['email'], (string) $params['email']) === 0) {
                $exception = new PDOException('Duplicate entry');
                $exception->errorInfo = ['23000', 1062, 'Duplicate entry'];
                throw $exception;
            }
        }

        $this->lastId = $this->nextId++;
        $this->rows[$this->lastId] = [
            'id' => $this->lastId,
            'display_name' => $params['display_name'],
            'email' => $params['email'],
            'password_hash' => $params['password_hash'],
            'desktop_profile_id' => null,
            'created_at' => '2026-09-29 12:00:00.000',
            'updated_at' => '2026-09-29 12:00:00.000',
        ];
    }
}

final class UserStoreStatement extends PDOStatement
{
    private array $params = [];

    public function __construct(
        private readonly UserStorePdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_starts_with($this->query, 'INSERT INTO users')) {
            $this->pdo->insert($this->params);
        } elseif (str_starts_with($this->query, 'UPDATE users SET desktop_profile_id')) {
            $id = (int) $this->params['id'];
            if ($this->pdo->raceProfileId !== null && isset($this->pdo->rows[$id])) {
                $this->pdo->rows[$id]['desktop_profile_id'] = $this->pdo->raceProfileId;
                $this->pdo->raceProfileId = null;
            }
            if (isset($this->pdo->rows[$id]) && $this->pdo->rows[$id]['desktop_profile_id'] === null) {
                $this->pdo->rows[$id]['desktop_profile_id'] = $this->params['profile_id'];
            }
        }

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'WHERE id =')) {
            return $this->pdo->rows[(int) ($this->params['id'] ?? 0)] ?? false;
        }
        if (str_contains($this->query, 'WHERE email =')) {
            foreach ($this->pdo->rows as $row) {
                if (strcasecmp($row['email'], (string) ($this->params['email'] ?? '')) === 0) {
                    return $row;
                }
            }
        }

        return false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->query, 'SELECT desktop_profile_id')) {
            return $this->pdo->rows[(int) ($this->params['id'] ?? 0)]['desktop_profile_id'] ?? false;
        }
        return false;
    }
}
