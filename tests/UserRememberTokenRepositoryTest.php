<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Repositories\UserRememberTokenRepository;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\RememberTokenPdo;

final class UserRememberTokenRepositoryTest extends TestCase
{
    private RememberTokenPdo $pdo;
    private UserRememberTokenRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new RememberTokenPdo();
        $this->pdo->seedUser();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $this->pdo);
        $this->repository = new UserRememberTokenRepository($database);
    }

    public function testCreateFindRotateAndDeleteBySelector(): void
    {
        $selector = str_repeat('a', 32);
        $oldHash = hash('sha256', str_repeat('b', 64));
        $newHash = hash('sha256', str_repeat('c', 64));

        $this->repository->create(1, $selector, $oldHash, 30);

        self::assertSame($oldHash, $this->repository->findBySelector($selector)['validator_hash']);
        self::assertFalse($this->repository->rotate($selector, str_repeat('0', 64), $newHash, 30));
        self::assertSame($oldHash, $this->pdo->tokens[$selector]['validator_hash']);
        self::assertTrue($this->repository->rotate($selector, $oldHash, $newHash, 30));
        self::assertSame($newHash, $this->pdo->tokens[$selector]['validator_hash']);
        self::assertNotNull($this->pdo->tokens[$selector]['last_used_at']);
        self::assertTrue($this->repository->deleteBySelector($selector));
        self::assertNull($this->repository->findBySelector($selector));
    }

    public function testExpiryAndDeleteByUserAreScoped(): void
    {
        $expired = str_repeat('d', 32);
        $active = str_repeat('e', 32);
        $this->pdo->seedUser(2);
        $this->repository->create(1, $expired, str_repeat('1', 64), 30);
        $this->repository->create(2, $active, str_repeat('2', 64), 30);
        $this->pdo->expire($expired);

        self::assertTrue($this->repository->findBySelector($expired)['expired']);
        self::assertSame(1, $this->repository->deleteExpired());
        self::assertArrayNotHasKey($expired, $this->pdo->tokens);
        self::assertSame(1, $this->repository->deleteByUser(2));
        self::assertSame([], $this->pdo->tokens);
    }

    public function testSelectorIsUnique(): void
    {
        $selector = str_repeat('f', 32);
        $this->repository->create(1, $selector, str_repeat('3', 64), 30);

        $this->expectException(PDOException::class);
        $this->repository->create(1, $selector, str_repeat('4', 64), 30);
    }

    public function testUserDeletionCascadesAndMigrationHasProtectedSchema(): void
    {
        $selector = str_repeat('a', 32);
        $this->repository->create(1, $selector, str_repeat('5', 64), 30);
        $this->pdo->deleteUser(1);
        self::assertNull($this->repository->findBySelector($selector));

        $migration = file_get_contents(
            dirname(__DIR__) . '/database/migrations/2026_10_05_000014_create_user_remember_tokens.sql',
        );
        self::assertStringContainsString('selector CHAR(32) CHARACTER SET ascii COLLATE ascii_bin', $migration);
        self::assertStringContainsString('validator_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin', $migration);
        self::assertStringContainsString('UNIQUE KEY user_remember_tokens_selector_unique (selector)', $migration);
        self::assertStringContainsString('FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE', $migration);
        self::assertStringNotContainsString('password', $migration);
        self::assertStringNotContainsString('session_id', $migration);
    }
}
