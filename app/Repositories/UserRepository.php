<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use PDOException;

final class UserRepository
{
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    public function __construct(private readonly Database $database)
    {
    }

    /** @return null|array{id: int, display_name: string, email: string, created_at: string, updated_at: string} */
    public function findById(int $id): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, display_name, email, created_at, updated_at FROM users WHERE id = :id LIMIT 1',
        );
        $statement->execute(['id' => $id]);

        return $this->publicUser($statement->fetch());
    }

    /** @return null|array{id: int, display_name: string, email: string, password_hash: string} */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, display_name, email, password_hash FROM users WHERE email = :email LIMIT 1',
        );
        $statement->execute(['email' => $this->normalizeEmail($email)]);
        $user = $statement->fetch();

        if (!is_array($user)) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'display_name' => (string) $user['display_name'],
            'email' => (string) $user['email'],
            'password_hash' => (string) $user['password_hash'],
        ];
    }

    public function create(string $displayName, string $email, string $passwordHash): ?int
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO users (display_name, email, password_hash) '
            . 'VALUES (:display_name, :email, :password_hash)',
        );

        try {
            $statement->execute([
                'display_name' => $displayName,
                'email' => $this->normalizeEmail($email),
                'password_hash' => $passwordHash,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateEmail($exception)) {
                return null;
            }

            throw $exception;
        }

        return (int) $this->database->connection()->lastInsertId();
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** @return null|array{id: int, display_name: string, email: string, created_at: string, updated_at: string} */
    private function publicUser(mixed $user): ?array
    {
        if (!is_array($user)) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'display_name' => (string) $user['display_name'],
            'email' => (string) $user['email'],
            'created_at' => (string) $user['created_at'],
            'updated_at' => (string) $user['updated_at'],
        ];
    }

    private function isDuplicateEmail(PDOException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_ENTRY;
    }
}
