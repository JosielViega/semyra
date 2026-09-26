<?php

declare(strict_types=1);

namespace App\Services;

final class RoomTransmissionPlayback
{
    public const MAX_TIME_MS = 315_576_000_000;

    private const STATES = ['playing', 'paused'];
    private const ACTIONS = ['play', 'pause', 'seek', 'live'];
    private const MEDIA_MODES = ['vod', 'live'];

    /**
     * @param array{action?: mixed, position_ms?: mixed, transmission_revision?: mixed, playback_revision?: mixed} $payload
     * @return array{action: string, state: string, position_ms: int, transmission_revision: int, playback_revision: int, at_live_edge: bool}
     */
    public function normalizeCommand(
        array $payload,
        string $currentState,
        string $currentMediaMode,
        int $currentPositionMs,
    ): array {
        $action = $payload['action'] ?? null;
        $positionMs = $action === 'live'
            ? $currentPositionMs
            : $this->integer($payload['position_ms'] ?? null);
        $transmissionRevision = $this->integer($payload['transmission_revision'] ?? null);
        $playbackRevision = $this->integer($payload['playback_revision'] ?? null);

        if (!is_string($action)
            || !in_array($action, self::ACTIONS, true)
            || !in_array($currentState, self::STATES, true)
            || !in_array($currentMediaMode, self::MEDIA_MODES, true)
            || !is_int($positionMs)
            || $positionMs < 0
            || $positionMs > self::MAX_TIME_MS
            || $transmissionRevision === null
            || $transmissionRevision < 1
            || $playbackRevision === null
            || $playbackRevision < 1
            || ($action === 'live' && $currentMediaMode !== 'live')) {
            throw new \InvalidArgumentException('Shared playback command is invalid.');
        }

        return [
            'action' => $action,
            'state' => match ($action) {
                'play', 'live' => 'playing',
                'pause' => 'paused',
                'seek' => $currentState,
            },
            'position_ms' => $positionMs,
            'transmission_revision' => $transmissionRevision,
            'playback_revision' => $playbackRevision,
            'at_live_edge' => $action === 'live',
        ];
    }

    /**
     * @param array{position_ms?: mixed, transmission_revision?: mixed, playback_revision?: mixed} $payload
     * @return array{position_ms: int, transmission_revision: int, playback_revision: int}
     */
    public function normalizeLiveEdgeObservation(array $payload): array
    {
        $positionMs = $this->integer($payload['position_ms'] ?? null);
        $transmissionRevision = $this->integer($payload['transmission_revision'] ?? null);
        $playbackRevision = $this->integer($payload['playback_revision'] ?? null);

        if ($positionMs === null
            || $positionMs < 0
            || $positionMs > self::MAX_TIME_MS
            || $transmissionRevision === null
            || $transmissionRevision < 1
            || $playbackRevision === null
            || $playbackRevision < 1) {
            throw new \InvalidArgumentException('Live edge observation is invalid.');
        }

        return [
            'position_ms' => $positionMs,
            'transmission_revision' => $transmissionRevision,
            'playback_revision' => $playbackRevision,
        ];
    }

    /**
     * @param array<string, mixed> $transmission
     * @return array{state: string, position_ms: int, revision: int, at_live_edge: bool, live_edge_position_ms: null|int}
     */
    public function present(array $transmission): array
    {
        $state = $transmission['playback_state'] ?? null;
        $mediaMode = $transmission['media_mode'] ?? null;
        $atLiveEdge = $this->boolean($transmission['playback_at_live_edge'] ?? null);
        $positionMs = $this->integer($transmission['playback_position_ms'] ?? null);
        $revision = $this->integer($transmission['playback_revision'] ?? null);
        $ageMs = $this->integer($transmission['playback_age_ms'] ?? null);
        $liveEdgePositionMs = ($transmission['projected_live_edge_position_ms'] ?? null) === null
            ? null
            : $this->integer($transmission['projected_live_edge_position_ms']);

        if (!is_string($state)
            || !in_array($state, self::STATES, true)
            || !is_string($mediaMode)
            || !in_array($mediaMode, ['unknown', ...self::MEDIA_MODES], true)
            || $atLiveEdge === null
            || ($mediaMode !== 'live' && $atLiveEdge)
            || $positionMs === null
            || $positionMs < 0
            || $positionMs > self::MAX_TIME_MS
            || $revision === null
            || $revision < 1
            || $ageMs === null
            || $ageMs < 0
            || ($liveEdgePositionMs !== null
                && ($liveEdgePositionMs < 0 || $liveEdgePositionMs > self::MAX_TIME_MS))) {
            throw new \InvalidArgumentException('Stored shared playback state is invalid.');
        }

        if ($atLiveEdge && $liveEdgePositionMs !== null) {
            $positionMs = $liveEdgePositionMs;
        } elseif ($state === 'playing') {
            $positionMs = min($positionMs + min($ageMs, self::MAX_TIME_MS), self::MAX_TIME_MS);
        }

        return [
            'state' => $state,
            'position_ms' => $positionMs,
            'revision' => $revision,
            'at_live_edge' => $atLiveEdge,
            'live_edge_position_ms' => $liveEdgePositionMs,
        ];
    }

    private function boolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => null,
        };
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/', $value) !== 1) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : null;
    }
}
