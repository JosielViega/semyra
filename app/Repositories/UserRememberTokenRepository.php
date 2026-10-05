<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRememberTokenRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(
        int $userId,
        string $selector,
        string $validatorHash,
        int $rememberDays,
    ): void {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO user_remember_tokens '
            . '(user_id, selector, validator_hash, expires_at) '
            . 'VALUES (:user_id, :selector, :validator_hash, '
            . 'DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :remember_days DAY))',
        );
        $statement->execute([
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => $validatorHash,
            'remember_days' => $rememberDays,
        ]);
    }

    /** @return null|array{id: int, user_id: int, selector: string, validator_hash: string, expires_at: string, expired: bool} */
    public function findBySelector(string $selector): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, user_id, selector, validator_hash, expires_at, '
            . '(expires_at <= CURRENT_TIMESTAMP(3)) AS expired '
            . 'FROM user_remember_tokens WHERE selector = :selector LIMIT 1',
        );
        $statement->execute(['selector' => $selector]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'selector' => (string) $row['selector'],
            'validator_hash' => (string) $row['validator_hash'],
            'expires_at' => (string) $row['expires_at'],
            'expired' => (bool) $row['expired'],
        ];
    }

    public function rotate(
        string $selector,
        string $expectedValidatorHash,
        string $validatorHash,
        int $rememberDays,
    ): bool {
        $statement = $this->database->connection()->prepare(
            'UPDATE user_remember_tokens SET validator_hash = :validator_hash, '
            . 'expires_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :remember_days DAY), '
            . 'last_used_at = CURRENT_TIMESTAMP(3) '
            . 'WHERE selector = :selector AND validator_hash = :expected_validator_hash',
        );
        $statement->execute([
            'validator_hash' => $validatorHash,
            'remember_days' => $rememberDays,
            'selector' => $selector,
            'expected_validator_hash' => $expectedValidatorHash,
        ]);

        return $statement->rowCount() === 1;
    }

    public function deleteBySelector(string $selector): bool
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM user_remember_tokens WHERE selector = :selector',
        );
        $statement->execute(['selector' => $selector]);

        return $statement->rowCount() === 1;
    }

    public function deleteExpired(): int
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM user_remember_tokens WHERE expires_at <= CURRENT_TIMESTAMP(3)',
        );
        $statement->execute();

        return $statement->rowCount();
    }

    public function deleteByUser(int $userId): int
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM user_remember_tokens WHERE user_id = :user_id',
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->rowCount();
    }
}
