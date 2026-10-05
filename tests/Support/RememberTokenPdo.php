<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOException;
use PDOStatement;

final class RememberTokenPdo extends PDO
{
    /** @var array<int, array<string, mixed>> */
    public array $users = [];

    /** @var array<string, array<string, mixed>> */
    public array $tokens = [];

    private int $nextTokenId = 1;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RememberTokenStatement($this, $query);
    }

    public function seedUser(int $id = 1): void
    {
        $this->users[$id] = [
            'id' => $id,
            'display_name' => 'Usuário de teste',
            'email' => 'user' . $id . '@example.test',
            'password_hash' => password_hash('synthetic-password', PASSWORD_DEFAULT),
            'created_at' => '2026-10-05 12:00:00.000',
            'updated_at' => '2026-10-05 12:00:00.000',
        ];
    }

    public function insertToken(array $params): void
    {
        $selector = (string) $params['selector'];
        if (isset($this->tokens[$selector])) {
            $exception = new PDOException('Duplicate selector');
            $exception->errorInfo = ['23000', 1062, 'Duplicate selector'];
            throw $exception;
        }

        $this->tokens[$selector] = [
            'id' => $this->nextTokenId++,
            'user_id' => (int) $params['user_id'],
            'selector' => $selector,
            'validator_hash' => (string) $params['validator_hash'],
            'expires_at' => '2099-10-05 12:00:00.000',
            'expired' => false,
            'last_used_at' => null,
        ];
    }

    public function expire(string $selector): void
    {
        $this->tokens[$selector]['expires_at'] = '2000-01-01 00:00:00.000';
        $this->tokens[$selector]['expired'] = true;
    }

    public function deleteUser(int $userId): void
    {
        unset($this->users[$userId]);
        foreach ($this->tokens as $selector => $token) {
            if ($token['user_id'] === $userId) {
                unset($this->tokens[$selector]);
            }
        }
    }
}

final class RememberTokenStatement extends PDOStatement
{
    private array $params = [];
    private int $affected = 0;

    public function __construct(
        private readonly RememberTokenPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        $this->affected = 0;

        if (str_starts_with($this->query, 'INSERT INTO user_remember_tokens')) {
            $this->pdo->insertToken($this->params);
            $this->affected = 1;
        } elseif (str_starts_with($this->query, 'UPDATE user_remember_tokens')) {
            $selector = (string) $this->params['selector'];
            $token = $this->pdo->tokens[$selector] ?? null;
            if ($token !== null
                && hash_equals($token['validator_hash'], (string) $this->params['expected_validator_hash'])) {
                $this->pdo->tokens[$selector]['validator_hash'] = (string) $this->params['validator_hash'];
                $this->pdo->tokens[$selector]['expires_at'] = '2099-10-05 12:00:00.000';
                $this->pdo->tokens[$selector]['expired'] = false;
                $this->pdo->tokens[$selector]['last_used_at'] = '2026-10-05 12:00:00.000';
                $this->affected = 1;
            }
        } elseif (str_starts_with($this->query, 'DELETE FROM user_remember_tokens WHERE selector')) {
            $selector = (string) $this->params['selector'];
            if (isset($this->pdo->tokens[$selector])) {
                unset($this->pdo->tokens[$selector]);
                $this->affected = 1;
            }
        } elseif (str_contains($this->query, 'WHERE expires_at <=')) {
            foreach ($this->pdo->tokens as $selector => $token) {
                if ($token['expired']) {
                    unset($this->pdo->tokens[$selector]);
                    ++$this->affected;
                }
            }
        } elseif (str_contains($this->query, 'WHERE user_id =')) {
            foreach ($this->pdo->tokens as $selector => $token) {
                if ($token['user_id'] === (int) $this->params['user_id']) {
                    unset($this->pdo->tokens[$selector]);
                    ++$this->affected;
                }
            }
        }

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM user_remember_tokens')) {
            return $this->pdo->tokens[(string) ($this->params['selector'] ?? '')] ?? false;
        }
        if (str_contains($this->query, 'FROM users WHERE id')) {
            return $this->pdo->users[(int) ($this->params['id'] ?? 0)] ?? false;
        }

        return false;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }
}
