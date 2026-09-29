<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRoomRepository
{
    private const TEMPORARY_TTL_HOURS = 24;

    public function __construct(private readonly Database $database)
    {
    }

    public function recordParticipation(int $userId, int $roomId): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO user_rooms (user_id, room_id) VALUES (:user_id, :room_id) '
            . 'ON DUPLICATE KEY UPDATE last_joined_at = CURRENT_TIMESTAMP(3)',
        );
        $statement->execute([
            'user_id' => $userId,
            'room_id' => $roomId,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function participatedByUser(int $userId): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT rooms.id, rooms.code, rooms.created_by_user_id, rooms.created_at, '
            . 'rooms.last_activity_at, user_rooms.first_joined_at, user_rooms.last_joined_at '
            . 'FROM user_rooms INNER JOIN rooms ON rooms.id = user_rooms.room_id '
            . 'WHERE user_rooms.user_id = :user_id '
            . 'AND (rooms.created_by_user_id IS NULL OR rooms.created_by_user_id <> :owner_user_id) '
            . 'AND (rooms.created_by_user_id IS NOT NULL OR rooms.last_activity_at >= '
            . 'CURRENT_TIMESTAMP(3) - INTERVAL ' . self::TEMPORARY_TTL_HOURS . ' HOUR) '
            . 'ORDER BY user_rooms.last_joined_at DESC, rooms.id DESC',
        );
        $statement->execute([
            'user_id' => $userId,
            'owner_user_id' => $userId,
        ]);

        return $statement->fetchAll();
    }
}
