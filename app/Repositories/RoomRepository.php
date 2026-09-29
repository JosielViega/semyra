<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDOException;

final class RoomRepository
{
    private const MYSQL_DUPLICATE_ENTRY = 1062;
    private const TEMPORARY_TTL_HOURS = 24;
    private const ACTIVITY_TOUCH_INTERVAL_SECONDS = 60;

    public function __construct(private readonly Database $database)
    {
    }

    public function tryCreate(string $code, ?int $creatorUserId): bool
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO rooms (code, created_by_user_id) VALUES (:code, :created_by_user_id)',
        );

        try {
            $statement->execute([
                'code' => $code,
                'created_by_user_id' => $creatorUserId,
            ]);
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
            'SELECT id, code, created_by_user_id, last_activity_at, created_at '
            . 'FROM rooms WHERE code = :code '
            . 'AND (created_by_user_id IS NOT NULL OR last_activity_at >= '
            . 'CURRENT_TIMESTAMP(3) - INTERVAL ' . self::TEMPORARY_TTL_HOURS . ' HOUR) '
            . 'LIMIT 1',
        );
        $statement->execute(['code' => $code]);
        $room = $statement->fetch();

        return is_array($room) ? $room : null;
    }

    /** @return list<array<string, mixed>> */
    public function createdByUser(int $userId): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, code, created_by_user_id, created_at, last_activity_at '
            . 'FROM rooms WHERE created_by_user_id = :user_id '
            . 'ORDER BY last_activity_at DESC, id DESC',
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function touchActivity(int $roomId): bool
    {
        $statement = $this->database->connection()->prepare(
            'UPDATE rooms SET last_activity_at = CURRENT_TIMESTAMP(3) '
            . 'WHERE id = :id AND last_activity_at < '
            . 'CURRENT_TIMESTAMP(3) - INTERVAL ' . self::ACTIVITY_TOUCH_INTERVAL_SECONDS . ' SECOND',
        );
        $statement->execute(['id' => $roomId]);

        return $statement->rowCount() === 1;
    }

    public function deleteExpiredTemporaryRooms(): int
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM rooms WHERE created_by_user_id IS NULL '
            . 'AND last_activity_at < CURRENT_TIMESTAMP(3) - INTERVAL '
            . self::TEMPORARY_TTL_HOURS . ' HOUR',
        );
        $statement->execute();

        return $statement->rowCount();
    }

    public function deleteExpiredTemporaryRoomByCode(string $code): bool
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM rooms WHERE code = :code AND created_by_user_id IS NULL '
            . 'AND last_activity_at < CURRENT_TIMESTAMP(3) - INTERVAL '
            . self::TEMPORARY_TTL_HOURS . ' HOUR',
        );
        $statement->execute(['code' => $code]);

        return $statement->rowCount() === 1;
    }

    private function isDuplicateCode(PDOException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_ENTRY;
    }
}
