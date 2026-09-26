'use strict';

((root, factory) => {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.SemyraMedia = api;
    }
})(typeof window === 'undefined' ? null : window, () => {
    const LIVE_EDGE_THRESHOLD_MS = 5000;

    const behindLiveMs = (liveEdgeMs, positionMs) => (
        Number.isSafeInteger(liveEdgeMs) ? Math.max(0, liveEdgeMs - positionMs) : null
    );

    const isNearLiveEdge = (liveEdgeMs, positionMs) => {
        const behindMs = behindLiveMs(liveEdgeMs, positionMs);
        return behindMs !== null && behindMs <= LIVE_EDGE_THRESHOLD_MS;
    };

    const formatTime = (milliseconds) => {
        const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        return hours > 0
            ? `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
            : `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    };

    const livePositionLabel = (liveEdgeMs, positionMs) => {
        const behindMs = behindLiveMs(liveEdgeMs, positionMs);
        if (behindMs === null) {
            return '—';
        }
        return behindMs <= LIVE_EDGE_THRESHOLD_MS ? '🔴 AO VIVO' : `-${formatTime(behindMs)}`;
    };

    const createScrubbingSession = () => {
        let active = false;
        let positionMs = null;

        return {
            start(initialPositionMs, allowed) {
                if (!allowed || !Number.isSafeInteger(initialPositionMs) || initialPositionMs < 0) {
                    return false;
                }
                active = true;
                positionMs = initialPositionMs;
                return true;
            },
            update(nextPositionMs) {
                if (!active || !Number.isSafeInteger(nextPositionMs) || nextPositionMs < 0) {
                    return false;
                }
                positionMs = nextPositionMs;
                return true;
            },
            cancel() {
                active = false;
                positionMs = null;
            },
            commit(mediaMode, liveEdgeMs) {
                if (!active || positionMs === null) {
                    return null;
                }
                const targetMs = positionMs;
                active = false;
                positionMs = null;
                if (mediaMode === 'live' && isNearLiveEdge(liveEdgeMs, targetMs)) {
                    return { action: 'live', positionMs: null };
                }
                return { action: 'seek', positionMs: targetMs };
            },
            isActive() {
                return active;
            },
            positionMs() {
                return positionMs;
            },
        };
    };

    const playbackPresentation = ({
        mediaMode,
        atLiveEdge,
        positionMs,
        durationMs,
        liveEdgeMs,
    }) => {
        if (mediaMode === 'live') {
            return atLiveEdge
                ? { kind: 'live-edge', current: '🔴 AO VIVO', duration: '' }
                : {
                    kind: 'live-dvr',
                    current: livePositionLabel(liveEdgeMs, positionMs),
                    duration: '',
                };
        }
        return {
            kind: 'vod',
            current: formatTime(positionMs),
            duration: formatTime(durationMs),
        };
    };

    return {
        LIVE_EDGE_THRESHOLD_MS,
        behindLiveMs,
        createScrubbingSession,
        formatTime,
        isNearLiveEdge,
        livePositionLabel,
        playbackPresentation,
    };
});
