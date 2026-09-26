<?php

declare(strict_types=1);

namespace App\Services;

final class RoomTransmissionPresenter
{
    public function __construct(
        private readonly RoomTransmissionPlayback $playback = new RoomTransmissionPlayback(),
    ) {
    }

    /**
     * @param null|array<string, mixed> $transmission
     * @return null|array{source: string, youtube_video_id: null|string, revision: int, owner_name: string, is_owner: bool, media_mode: string, playback: array{state: string, position_ms: int, revision: int, at_live_edge: bool, live_edge_position_ms: null|int}}
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
            'media_mode' => (string) $transmission['media_mode'],
            'owner_name' => (string) ($transmission['owner_name'] ?? 'Participante'),
            'is_owner' => hash_equals(
                (string) $transmission['owner_participant_key_hash'],
                $currentParticipantKeyHash,
            ),
            'playback' => $this->playback->present($transmission),
        ];
    }
}
