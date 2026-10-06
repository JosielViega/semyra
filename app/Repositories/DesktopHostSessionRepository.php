<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class DesktopHostSessionRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(
        int $roomId,
        string $instanceId,
        int $revision,
        ?int $ownerUserId,
        string $ownerParticipantKeyHash,
        string $permission,
        string $selector,
        string $validatorHash,
        string $expiresAt,
    ): bool {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO desktop_host_sessions '
            . '(room_id, transmission_instance_id, transmission_revision, owner_user_id, '
            . 'owner_participant_key_hash, permission, selector, validator_hash, expires_at) '
            . 'SELECT transmission.room_id, transmission.instance_id, transmission.revision, '
            . 'transmission.owner_user_id, transmission.owner_participant_key_hash, '
            . ':permission, :selector, :validator_hash, :expires_at '
            . 'FROM room_transmissions transmission '
            . 'WHERE transmission.room_id = :room_id '
            . 'AND transmission.instance_id = :transmission_instance_id '
            . 'AND transmission.revision = :transmission_revision '
            . "AND transmission.source_type = 'iptv' AND transmission.media_mode = 'live' "
            . 'AND ((transmission.owner_user_id IS NOT NULL AND transmission.owner_user_id = :owner_user_id) '
            . 'OR (transmission.owner_user_id IS NULL '
            . 'AND transmission.owner_participant_key_hash = :owner_participant_key_hash))',
        );
        $statement->execute([
            'room_id' => $roomId,
            'transmission_instance_id' => $instanceId,
            'transmission_revision' => $revision,
            'owner_user_id' => $ownerUserId,
            'owner_participant_key_hash' => $ownerParticipantKeyHash,
            'permission' => $permission,
            'selector' => $selector,
            'validator_hash' => $validatorHash,
            'expires_at' => $expiresAt,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @return null|array<string, mixed> */
    public function findBySelector(string $selector): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, room_id, transmission_instance_id, transmission_revision, owner_user_id, '
            . 'owner_participant_key_hash, permission, selector, validator_hash, expires_at, created_at, revoked_at, '
            . '(expires_at <= CURRENT_TIMESTAMP(3)) AS expired '
            . 'FROM desktop_host_sessions WHERE selector = :selector LIMIT 1',
        );
        $statement->execute(['selector' => $selector]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function revokeBySelector(string $selector): bool
    {
        $statement = $this->database->connection()->prepare(
            'UPDATE desktop_host_sessions SET revoked_at = CURRENT_TIMESTAMP(3) '
            . 'WHERE selector = :selector AND revoked_at IS NULL',
        );
        $statement->execute(['selector' => $selector]);

        return $statement->rowCount() === 1;
    }

    public function revokeEquivalent(
        int $roomId,
        string $instanceId,
        int $revision,
        ?int $ownerUserId,
        string $ownerParticipantKeyHash,
        string $permission,
    ): int {
        $statement = $this->database->connection()->prepare(
            'UPDATE desktop_host_sessions SET revoked_at = CURRENT_TIMESTAMP(3) '
            . 'WHERE room_id = :room_id '
            . 'AND transmission_instance_id = :transmission_instance_id '
            . 'AND transmission_revision = :transmission_revision '
            . 'AND permission = :permission AND revoked_at IS NULL '
            . 'AND ((owner_user_id IS NOT NULL AND owner_user_id = :owner_user_id) '
            . 'OR (owner_user_id IS NULL AND owner_participant_key_hash = :owner_participant_key_hash))',
        );
        $statement->execute([
            'room_id' => $roomId,
            'transmission_instance_id' => $instanceId,
            'transmission_revision' => $revision,
            'owner_user_id' => $ownerUserId,
            'owner_participant_key_hash' => $ownerParticipantKeyHash,
            'permission' => $permission,
        ]);

        return $statement->rowCount();
    }

    public function deleteExpired(): int
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM desktop_host_sessions WHERE expires_at <= CURRENT_TIMESTAMP(3)',
        );
        $statement->execute();

        return $statement->rowCount();
    }
}
