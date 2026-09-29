<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Repositories\UserRepository;
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
}

final class UserStorePdo extends PDO
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 1;
    private int $lastId = 0;

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
}
