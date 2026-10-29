<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class RoomMessageRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return null|array<string, mixed> */
    public function findByClientId(int $roomId, string $participantKeyHash, string $clientMessageId): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, room_id, participant_key_hash, user_id, display_name, message, created_at '
            . 'FROM room_messages WHERE room_id = :room_id '
            . 'AND participant_key_hash = :participant_key_hash '
            . 'AND client_message_id = :client_message_id LIMIT 1',
        );
        $statement->execute([
            'room_id' => $roomId,
            'participant_key_hash' => $participantKeyHash,
            'client_message_id' => $clientMessageId,
        ]);
        $message = $statement->fetch();

        return is_array($message) ? $message : null;
    }

    /** @return array<string, mixed> */
    public function create(
        int $roomId,
        string $participantKeyHash,
        ?int $userId,
        string $displayName,
        string $clientMessageId,
        string $message,
    ): array {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO room_messages '
            . '(room_id, participant_key_hash, user_id, display_name, client_message_id, message) '
            . 'VALUES (:room_id, :participant_key_hash, :user_id, :display_name, :client_message_id, :message)',
        );
        $statement->execute([
            'room_id' => $roomId,
            'participant_key_hash' => $participantKeyHash,
            'user_id' => $userId,
            'display_name' => $displayName,
            'client_message_id' => $clientMessageId,
            'message' => $message,
        ]);

        return $this->findByClientId($roomId, $participantKeyHash, $clientMessageId)
            ?? throw new \RuntimeException('Created room message could not be read.');
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $roomId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $statement = $this->database->connection()->prepare(
            'SELECT id, room_id, participant_key_hash, user_id, display_name, message, created_at '
            . 'FROM (SELECT id, room_id, participant_key_hash, user_id, display_name, message, created_at '
            . 'FROM room_messages WHERE room_id = :room_id ORDER BY id DESC LIMIT ' . $limit . ') recent '
            . 'ORDER BY id ASC',
        );
        $statement->execute(['room_id' => $roomId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function after(int $roomId, int $afterId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $statement = $this->database->connection()->prepare(
            'SELECT id, room_id, participant_key_hash, user_id, display_name, message, created_at '
            . 'FROM room_messages WHERE room_id = :room_id AND id > :after_id '
            . 'ORDER BY id ASC LIMIT ' . $limit,
        );
        $statement->execute(['room_id' => $roomId, 'after_id' => $afterId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function before(int $roomId, int $beforeId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $statement = $this->database->connection()->prepare(
            'SELECT id, room_id, participant_key_hash, user_id, display_name, message, created_at '
            . 'FROM (SELECT id, room_id, participant_key_hash, user_id, display_name, message, created_at '
            . 'FROM room_messages WHERE room_id = :room_id AND id < :before_id '
            . 'ORDER BY id DESC LIMIT ' . $limit . ') older ORDER BY id ASC',
        );
        $statement->execute(['room_id' => $roomId, 'before_id' => $beforeId]);

        return $statement->fetchAll();
    }
}
