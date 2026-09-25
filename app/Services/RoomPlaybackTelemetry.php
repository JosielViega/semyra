<?php

declare(strict_types=1);

namespace App\Services;

final class RoomPlaybackTelemetry
{
    public const FRESH_WINDOW_MS = 12_000;
    public const MAX_TIME_MS = 315_576_000_000;

    private const ALLOWED_STATES = [-1, 0, 1, 2, 3, 5];
    private const PLAYING_STATE = 1;

    /**
     * @param array{player_state?: mixed, player_position_ms?: mixed, player_duration_ms?: mixed} $payload
     * @return null|array{state: int, position_ms: int, duration_ms: int}
     */
    public function normalizePayload(array $payload): ?array
    {
        $values = [
            $payload['player_state'] ?? null,
            $payload['player_position_ms'] ?? null,
            $payload['player_duration_ms'] ?? null,
        ];
        $provided = count(array_filter($values, static fn (mixed $value): bool => $value !== null));

        if ($provided === 0) {
            return null;
        }

        if ($provided !== 3) {
            throw new \InvalidArgumentException('Playback telemetry must be complete.');
        }

        $state = $this->integer($values[0]);
        $positionMs = $this->integer($values[1]);
        $durationMs = $this->integer($values[2]);

        if ($state === null
            || !in_array($state, self::ALLOWED_STATES, true)
            || $positionMs === null
            || $positionMs < 0
            || $positionMs > self::MAX_TIME_MS
            || $durationMs === null
            || $durationMs < 0
            || $durationMs > self::MAX_TIME_MS) {
            throw new \InvalidArgumentException('Playback telemetry is invalid.');
        }

        return [
            'state' => $state,
            'position_ms' => $positionMs,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @param list<array<string, mixed>> $participants
     * @return list<array{name: string, is_you: bool, playback: null|array{state: int, position_ms: int, duration_ms: int, age_ms: int, fresh: bool, drift_ms: null|int}}>
     */
    public function presentParticipants(array $participants, string $currentParticipantKeyHash): array
    {
        $presented = array_map(function (array $participant) use ($currentParticipantKeyHash): array {
            return [
                'name' => (string) $participant['display_name'],
                'is_you' => hash_equals(
                    $currentParticipantKeyHash,
                    (string) $participant['participant_key_hash'],
                ),
                'playback' => $this->playbackFromRow($participant),
            ];
        }, $participants);

        $referencePositionMs = null;
        foreach ($presented as $participant) {
            $playback = $participant['playback'];
            if ($participant['is_you']
                && $playback !== null
                && $playback['fresh']
                && $playback['state'] === self::PLAYING_STATE) {
                $referencePositionMs = $playback['position_ms'];
                break;
            }
        }

        return array_map(static function (array $participant) use ($referencePositionMs): array {
            $playback = $participant['playback'];
            if ($playback !== null
                && $referencePositionMs !== null
                && $playback['fresh']
                && $playback['state'] === self::PLAYING_STATE) {
                $playback['drift_ms'] = $playback['position_ms'] - $referencePositionMs;
                $participant['playback'] = $playback;
            }

            return $participant;
        }, $presented);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) !== 1) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : null;
    }

    /**
     * @param array<string, mixed> $participant
     * @return null|array{state: int, position_ms: int, duration_ms: int, age_ms: int, fresh: bool, drift_ms: null|int}
     */
    private function playbackFromRow(array $participant): ?array
    {
        if (($participant['player_state'] ?? null) === null
            || ($participant['player_position_ms'] ?? null) === null
            || ($participant['player_duration_ms'] ?? null) === null
            || ($participant['player_sample_age_ms'] ?? null) === null) {
            return null;
        }

        $state = $this->integer($participant['player_state']);
        $positionMs = $this->integer($participant['player_position_ms']);
        $durationMs = $this->integer($participant['player_duration_ms']);
        $ageMs = $this->integer($participant['player_sample_age_ms']);

        if ($state === null
            || !in_array($state, self::ALLOWED_STATES, true)
            || $positionMs === null
            || $positionMs < 0
            || $positionMs > self::MAX_TIME_MS
            || $durationMs === null
            || $durationMs < 0
            || $durationMs > self::MAX_TIME_MS
            || $ageMs === null
            || $ageMs < 0) {
            return null;
        }

        $ageMs = min($ageMs, self::MAX_TIME_MS);
        $fresh = $ageMs <= self::FRESH_WINDOW_MS;
        if ($fresh && $state === self::PLAYING_STATE) {
            $positionMs = min($positionMs + $ageMs, self::MAX_TIME_MS);
        }

        return [
            'state' => $state,
            'position_ms' => $positionMs,
            'duration_ms' => $durationMs,
            'age_ms' => $ageMs,
            'fresh' => $fresh,
            'drift_ms' => null,
        ];
    }
}
