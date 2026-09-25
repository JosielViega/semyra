<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RoomParticipantRepository
{
    private const ACTIVE_WINDOW_SECONDS = 45;

    public function __construct(private readonly Database $database)
    {
    }

    public function touch(int $roomId, string $participantKeyHash, string $displayName): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO room_participants (room_id, participant_key_hash, display_name) '
            . 'VALUES (:room_id, :participant_key_hash, :display_name) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'display_name = VALUES(display_name), last_seen_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'room_id' => $roomId,
            'participant_key_hash' => $participantKeyHash,
            'display_name' => $displayName,
        ]);
    }

    /** @return list<array{participant_key_hash: string, display_name: string}> */
    public function activeForRoom(int $roomId): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT participant_key_hash, display_name '
            . 'FROM room_participants '
            . 'WHERE room_id = :room_id '
            . 'AND last_seen_at >= CURRENT_TIMESTAMP - INTERVAL ' . self::ACTIVE_WINDOW_SECONDS . ' SECOND '
            . 'ORDER BY created_at ASC, id ASC',
        );
        $statement->execute(['room_id' => $roomId]);

        return $statement->fetchAll();
    }
}
