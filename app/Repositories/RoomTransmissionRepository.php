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
            . 'transmission.source_type, transmission.youtube_video_id, transmission.media_mode, '
            . 'transmission.revision, '
            . 'transmission.playback_state, transmission.playback_position_ms, '
            . 'transmission.playback_at_live_edge, transmission.playback_revision, '
            . 'GREATEST(0, TIMESTAMPDIFF(MICROSECOND, transmission.playback_updated_at, '
            . 'CURRENT_TIMESTAMP(3)) DIV 1000) AS playback_age_ms, '
            . 'CASE WHEN transmission.live_edge_position_ms IS NULL THEN NULL '
            . 'ELSE LEAST(315576000000, transmission.live_edge_position_ms + '
            . 'GREATEST(0, TIMESTAMPDIFF(MICROSECOND, transmission.live_edge_updated_at, '
            . 'CURRENT_TIMESTAMP(3)) DIV 1000)) END AS projected_live_edge_position_ms, '
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
        string $mediaMode,
    ): void {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO room_transmissions '
            . '(room_id, owner_participant_key_hash, source_type, youtube_video_id, media_mode, playback_at_live_edge) '
            . 'VALUES (:room_id, :owner_participant_key_hash, :source_type, :youtube_video_id, '
            . ':media_mode, :playback_at_live_edge) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'owner_participant_key_hash = VALUES(owner_participant_key_hash), '
            . 'source_type = VALUES(source_type), youtube_video_id = VALUES(youtube_video_id), '
            . 'media_mode = VALUES(media_mode), revision = revision + 1, '
            . 'playback_state = \'playing\', playback_position_ms = 0, '
            . 'playback_at_live_edge = VALUES(playback_at_live_edge), '
            . 'playback_revision = 1, playback_updated_at = CURRENT_TIMESTAMP(3), '
            . 'live_edge_position_ms = NULL, live_edge_updated_at = NULL, '
            . 'started_at = CURRENT_TIMESTAMP(3), updated_at = CURRENT_TIMESTAMP(3)',
        );
        $statement->execute([
            'room_id' => $roomId,
            'owner_participant_key_hash' => $ownerParticipantKeyHash,
            'source_type' => $sourceType,
            'youtube_video_id' => $youtubeVideoId,
            'media_mode' => $mediaMode,
            'playback_at_live_edge' => $mediaMode === 'live' ? 1 : 0,
        ]);
    }

    public function end(int $roomId, string $ownerParticipantKeyHash): bool
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM room_transmissions '
            . 'WHERE room_id = :room_id '
            . 'AND owner_participant_key_hash = :owner_participant_key_hash',
        );
        $statement->execute([
            'room_id' => $roomId,
            'owner_participant_key_hash' => $ownerParticipantKeyHash,
        ]);

        return $statement->rowCount() === 1;
    }

    public function updatePlayback(
        int $roomId,
        string $ownerParticipantKeyHash,
        int $transmissionRevision,
        int $playbackRevision,
        string $state,
        int $positionMs,
        bool $atLiveEdge,
    ): bool {
        $statement = $this->database->connection()->prepare(
            'UPDATE room_transmissions SET playback_state = :playback_state, '
            . 'playback_position_ms = :playback_position_ms, '
            . 'playback_at_live_edge = :playback_at_live_edge, '
            . 'playback_revision = playback_revision + 1, '
            . 'playback_updated_at = CURRENT_TIMESTAMP(3) '
            . 'WHERE room_id = :room_id '
            . 'AND owner_participant_key_hash = :owner_participant_key_hash '
            . 'AND revision = :transmission_revision '
            . 'AND playback_revision = :playback_revision',
        );
        $statement->execute([
            'playback_state' => $state,
            'playback_position_ms' => $positionMs,
            'playback_at_live_edge' => $atLiveEdge ? 1 : 0,
            'room_id' => $roomId,
            'owner_participant_key_hash' => $ownerParticipantKeyHash,
            'transmission_revision' => $transmissionRevision,
            'playback_revision' => $playbackRevision,
        ]);

        return $statement->rowCount() === 1;
    }

    public function observeLiveEdge(
        int $roomId,
        string $ownerParticipantKeyHash,
        int $transmissionRevision,
        int $playbackRevision,
        int $positionMs,
    ): bool {
        $statement = $this->database->connection()->prepare(
            'UPDATE room_transmissions SET live_edge_position_ms = :live_edge_position_ms, '
            . 'live_edge_updated_at = CURRENT_TIMESTAMP(3) '
            . 'WHERE room_id = :room_id '
            . 'AND owner_participant_key_hash = :owner_participant_key_hash '
            . 'AND revision = :transmission_revision '
            . 'AND playback_revision = :playback_revision '
            . 'AND media_mode = \'live\' '
            . 'AND playback_at_live_edge = 1 '
            . 'AND playback_state = \'playing\'',
        );
        $statement->execute([
            'live_edge_position_ms' => $positionMs,
            'room_id' => $roomId,
            'owner_participant_key_hash' => $ownerParticipantKeyHash,
            'transmission_revision' => $transmissionRevision,
            'playback_revision' => $playbackRevision,
        ]);

        return $statement->rowCount() === 1;
    }
}
