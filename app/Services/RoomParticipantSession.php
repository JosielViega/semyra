<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;

final class RoomParticipantSession
{
    private const SESSION_KEY = 'room_participants';

    public function __construct(private readonly Session $session)
    {
    }

    /** @return null|array{participant_key: string, display_name: string} */
    public function identityFor(string $roomCode): ?array
    {
        $participants = $this->session->get(self::SESSION_KEY, []);
        $identity = is_array($participants) ? ($participants[$roomCode] ?? null) : null;

        if (!is_array($identity)) {
            return null;
        }

        $participantKey = $identity['participant_key'] ?? null;
        $displayName = $identity['display_name'] ?? null;

        if (!is_string($participantKey)
            || preg_match('/^[a-f0-9]{64}$/', $participantKey) !== 1
            || !is_string($displayName)
            || $displayName === '') {
            return null;
        }

        return [
            'participant_key' => $participantKey,
            'display_name' => $displayName,
        ];
    }

    /** @return array{participant_key: string, display_name: string} */
    public function remember(string $roomCode, string $displayName): array
    {
        $participants = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($participants)) {
            $participants = [];
        }

        $existing = $this->identityFor($roomCode);
        $identity = [
            'participant_key' => $existing['participant_key'] ?? bin2hex(random_bytes(32)),
            'display_name' => $displayName,
        ];
        $participants[$roomCode] = $identity;
        $this->session->put(self::SESSION_KEY, $participants);

        return $identity;
    }
}
