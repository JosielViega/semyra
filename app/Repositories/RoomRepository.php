<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDOException;

final class RoomRepository
{
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    public function __construct(private readonly Database $database)
    {
    }

    public function tryCreate(string $code): bool
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO rooms (code) VALUES (:code)',
        );

        try {
            $statement->execute(['code' => $code]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateCode($exception)) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    public function findByCode(string $code): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, code, created_at FROM rooms WHERE code = :code LIMIT 1',
        );
        $statement->execute(['code' => $code]);
        $room = $statement->fetch();

        return is_array($room) ? $room : null;
    }

    private function isDuplicateCode(PDOException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_ENTRY;
    }
}
