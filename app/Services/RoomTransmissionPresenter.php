<?php

declare(strict_types=1);

namespace App\Services;

final class RoomTransmissionPresenter
{
    /**
     * @param null|array<string, mixed> $transmission
     * @return null|array{source: string, youtube_video_id: null|string, revision: int, owner_name: string, is_owner: bool}
     */
    public function present(?array $transmission, string $currentParticipantKeyHash): ?array
    {
        if ($transmission === null) {
            return null;
        }

        return [
            'source' => (string) $transmission['source_type'],
            'youtube_video_id' => is_string($transmission['youtube_video_id'] ?? null)
                ? $transmission['youtube_video_id']
                : null,
            'revision' => (int) $transmission['revision'],
            'owner_name' => (string) ($transmission['owner_name'] ?? 'Participante'),
            'is_owner' => hash_equals(
                (string) $transmission['owner_participant_key_hash'],
                $currentParticipantKeyHash,
            ),
        ];
    }
}
