<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(private readonly array $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $charset = preg_match('/^[a-zA-Z0-9_]+$/', (string) $this->config['charset']) === 1
            ? $this->config['charset']
            : 'utf8mb4';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $charset,
        );

        try {
            $this->connection = new PDO(
                $dsn,
                (string) $this->config['username'],
                (string) $this->config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new \RuntimeException('Database connection failed.', 0, $exception);
        }

        return $this->connection;
    }
}
