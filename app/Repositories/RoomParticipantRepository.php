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

    /** @param null|array{state: int, position_ms: int, duration_ms: int} $playback */
    public function touch(
        int $roomId,
        string $participantKeyHash,
        string $displayName,
        ?array $playback = null,
    ): void
    {
        if ($playback !== null) {
            $statement = $this->database->connection()->prepare(
                'INSERT INTO room_participants '
                . '(room_id, participant_key_hash, display_name, player_state, player_position_ms, '
                . 'player_duration_ms, player_sampled_at) '
                . 'VALUES (:room_id, :participant_key_hash, :display_name, :player_state, '
                . ':player_position_ms, :player_duration_ms, CURRENT_TIMESTAMP(3)) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'display_name = VALUES(display_name), last_seen_at = CURRENT_TIMESTAMP, '
                . 'player_state = VALUES(player_state), player_position_ms = VALUES(player_position_ms), '
                . 'player_duration_ms = VALUES(player_duration_ms), player_sampled_at = CURRENT_TIMESTAMP(3)',
            );
            $statement->execute([
                'room_id' => $roomId,
                'participant_key_hash' => $participantKeyHash,
                'display_name' => $displayName,
                'player_state' => $playback['state'],
                'player_position_ms' => $playback['position_ms'],
                'player_duration_ms' => $playback['duration_ms'],
            ]);
            return;
        }

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

    /** @return list<array<string, mixed>> */
    public function activeForRoom(int $roomId): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT participant_key_hash, display_name, player_state, player_position_ms, '
            . 'player_duration_ms, '
            . 'CASE WHEN player_sampled_at IS NULL THEN NULL '
            . 'ELSE GREATEST(0, TIMESTAMPDIFF(MICROSECOND, player_sampled_at, CURRENT_TIMESTAMP(3)) DIV 1000) '
            . 'END AS player_sample_age_ms '
            . 'FROM room_participants '
            . 'WHERE room_id = :room_id '
            . 'AND last_seen_at >= CURRENT_TIMESTAMP - INTERVAL ' . self::ACTIVE_WINDOW_SECONDS . ' SECOND '
            . 'ORDER BY created_at ASC, id ASC',
        );
        $statement->execute(['room_id' => $roomId]);

        return $statement->fetchAll();
    }
}
