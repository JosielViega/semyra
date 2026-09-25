<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RoomTransmissionRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return null|array<string, mixed> */
    public function findByRoom(int $roomId): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT transmission.room_id, transmission.owner_participant_key_hash, '
            . 'transmission.source_type, transmission.youtube_video_id, transmission.revision, '
            . 'transmission.started_at, transmission.updated_at, '
            . 'COALESCE(NULLIF(participant.display_name, \'\'), \'Participante\') AS owner_name '
            . 'FROM room_transmissions transmission '
            . 'LEFT JOIN room_participants participant '
            . 'ON participant.room_id = transmission.room_id '
            . 'AND participant.participant_key_hash = transmission.owner_participant_key_hash '
            . 'WHERE transmission.room_id = :room_id LIMIT 1',
        );
        $statement->execute(['room_id' => $roomId]);
        $transmission = $statement->fetch();

        return is_array($transmission) ? $transmission : null;
    }

    public function startOrReplace(
        int $roomId,
        string $ownerParticipantKeyHash,
        string $sourceType,
        ?string $youtubeVideoId,
    ): void {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO room_transmissions '
            . '(room_id, owner_participant_key_hash, source_type, youtube_video_id) '
            . 'VALUES (:room_id, :owner_participant_key_hash, :source_type, :youtube_video_id) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'owner_participant_key_hash = VALUES(owner_participant_key_hash), '
            . 'source_type = VALUES(source_type), youtube_video_id = VALUES(youtube_video_id), '
            . 'revision = revision + 1, started_at = CURRENT_TIMESTAMP(3), updated_at = CURRENT_TIMESTAMP(3)',
        );
        $statement->execute([
            'room_id' => $roomId,
            'owner_participant_key_hash' => $ownerParticipantKeyHash,
            'source_type' => $sourceType,
            'youtube_video_id' => $youtubeVideoId,
        ]);
    }

    public function end(int $roomId): void
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM room_transmissions WHERE room_id = :room_id',
        );
        $statement->execute(['room_id' => $roomId]);
    }
}
