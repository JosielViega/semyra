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
    const LIVE_SCRUB_THRESHOLD_MS = 5000;
    const SYNC_SETTLE_DELAY_MS = 2000;
    const PARTICIPANT_SYNC_WARMUP_MS = 4000;
    const AUTO_RESYNC_RETRY_DELAY_MS = 5000;
    const PLAYER_VOLUME_STORAGE_KEY = 'semyra:player-volume';
    const DEFAULT_PLAYER_VOLUME = 100;

    const normalizePlayerVolume = (value, fallback = DEFAULT_PLAYER_VOLUME) => {
        const normalizedFallback = Number.isFinite(Number(fallback))
            ? Math.min(100, Math.max(0, Math.round(Number(fallback))))
            : DEFAULT_PLAYER_VOLUME;
        if ((typeof value !== 'number' && typeof value !== 'string')
            || (typeof value === 'string' && value.trim() === '')) {
            return normalizedFallback;
        }
        const numericValue = Number(value);
        return Number.isFinite(numericValue)
            ? Math.min(100, Math.max(0, Math.round(numericValue)))
            : normalizedFallback;
    };

    const readStoredPlayerVolume = (storage, fallback = DEFAULT_PLAYER_VOLUME) => {
        try {
            const stored = storage?.getItem(PLAYER_VOLUME_STORAGE_KEY);
            return stored === null || stored === undefined
                ? normalizePlayerVolume(fallback)
                : normalizePlayerVolume(stored, fallback);
        } catch {
            return normalizePlayerVolume(fallback);
        }
    };

    const writeStoredPlayerVolume = (storage, volume) => {
        const normalized = normalizePlayerVolume(volume);
        try {
            storage?.setItem(PLAYER_VOLUME_STORAGE_KEY, String(normalized));
        } catch {
            // Persisting the local preference is optional.
        }
        return normalized;
    };

    const playerVolumeSelection = (value, previousNonZero = DEFAULT_PLAYER_VOLUME) => {
        const volume = normalizePlayerVolume(value);
        const fallback = normalizePlayerVolume(previousNonZero);
        return {
            muted: volume === 0,
            volume,
            restoreVolume: volume > 0 ? volume : (fallback > 0 ? fallback : DEFAULT_PLAYER_VOLUME),
        };
    };

    const playerUnmuteVolume = (volume, previousNonZero = DEFAULT_PLAYER_VOLUME) => {
        const selected = normalizePlayerVolume(volume);
        if (selected > 0) {
            return selected;
        }
        const previous = normalizePlayerVolume(previousNonZero);
        return previous > 0 ? previous : DEFAULT_PLAYER_VOLUME;
    };

    const behindLiveMs = (liveEdgeMs, positionMs) => (
        Number.isSafeInteger(liveEdgeMs) ? Math.max(0, liveEdgeMs - positionMs) : null
    );

    const isNearLiveEdge = (liveEdgeMs, positionMs) => {
        const behindMs = behindLiveMs(liveEdgeMs, positionMs);
        return behindMs !== null && behindMs <= LIVE_SCRUB_THRESHOLD_MS;
    };

    const liveSyncTargetMs = (physicalLiveEdgeMs, syncDelayMs) => (
        Number.isSafeInteger(physicalLiveEdgeMs)
        && physicalLiveEdgeMs >= 0
        && Number.isSafeInteger(syncDelayMs)
        && syncDelayMs >= 0
            ? Math.max(0, physicalLiveEdgeMs - syncDelayMs)
            : null
    );

    const liveRangeMaxMs = (liveSyncPositionMs) => (
        Number.isSafeInteger(liveSyncPositionMs) && liveSyncPositionMs >= 0
            ? liveSyncPositionMs
            : null
    );

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
        return behindMs <= LIVE_SCRUB_THRESHOLD_MS ? 'AO VIVO' : `-${formatTime(behindMs)}`;
    };

    const sharedPlaybackDispatchKey = (
        transmissionRevision,
        playbackRevision,
        liveSyncPositionMs,
    ) => `${transmissionRevision}:${playbackRevision}:${liveSyncPositionMs === null ? 0 : 1}`;

    const localOfficialDriftMs = ({
        localState,
        officialState,
        localPositionMs,
        officialPositionMs,
    }) => {
        const compatible = (officialState === 'playing' && localState === 1)
            || (officialState === 'paused' && localState === 2);
        if (!compatible
            || !Number.isSafeInteger(localPositionMs)
            || !Number.isSafeInteger(officialPositionMs)) {
            return null;
        }
        return localPositionMs - officialPositionMs;
    };

    const shouldBootstrapLiveEdge = ({
        isOwner,
        mediaMode,
        atLiveEdge,
        playbackState,
        liveEdgePositionMs,
        playerState,
        elapsedMs,
        retryIntervalMs,
    }) => isOwner === true
        && mediaMode === 'live'
        && atLiveEdge === true
        && playbackState === 'playing'
        && liveEdgePositionMs === null
        && playerState === 1
        && Number.isFinite(elapsedMs)
        && elapsedMs >= retryIntervalMs;

    const resyncPlan = ({mediaMode, state, atLiveEdge}) => {
        if (!['vod', 'live'].includes(mediaMode)
            || !['playing', 'paused'].includes(state)
            || typeof atLiveEdge !== 'boolean'
            || (mediaMode !== 'live' && atLiveEdge)) {
            return null;
        }

        if (state === 'paused') {
            return {firstAction: 'seek', resumeAction: null};
        }

        return {
            firstAction: 'pause',
            resumeAction: mediaMode === 'live' && atLiveEdge ? 'live' : 'play',
        };
    };

    const createResyncLock = () => {
        let active = false;
        return {
            active: () => active,
            begin: () => {
                if (active) {
                    return false;
                }
                active = true;
                return true;
            },
            end: () => {
                active = false;
            },
        };
    };

    const resyncContextMatches = (expected, current) => expected !== null
        && current !== null
        && current.isOwner === true
        && current.transmissionRevision === expected.transmissionRevision
        && current.playbackRevision === expected.playbackRevision;

    const participantSyncStorageKey = (scope, transmissionRevision) => (
        `semyra:resync:${scope}:${transmissionRevision}`
    );

    const createParticipantSyncTracker = ({
        synchronizedIds = [],
        warmupMs = PARTICIPANT_SYNC_WARMUP_MS,
        retryDelayMs = AUTO_RESYNC_RETRY_DELAY_MS,
    } = {}) => {
        const validId = (value) => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
        const synchronized = new Set(synchronizedIds.filter(validId));
        const readySince = new Map();
        let ready = new Set();
        let retryAfter = 0;

        const observe = ({participants, now, officialState, allowPausedStabilization = true}) => {
            const active = new Set();
            ready = new Set();
            if (Array.isArray(participants)) {
                participants.forEach((participant) => {
                    const id = validId(participant?.playback_instance_id)
                        ? participant.playback_instance_id
                        : participant?.public_id;
                    if (!validId(id)) {
                        return;
                    }
                    active.add(id);
                    if (participant?.playback !== null && participant?.playback?.fresh === true) {
                        ready.add(id);
                    }
                });
            }

            let changed = false;
            synchronized.forEach((id) => {
                if (!active.has(id)) {
                    synchronized.delete(id);
                    changed = true;
                }
            });
            readySince.forEach((_seenAt, id) => {
                if (!ready.has(id) || synchronized.has(id)) {
                    readySince.delete(id);
                }
            });
            ready.forEach((id) => {
                if (!synchronized.has(id) && !readySince.has(id)) {
                    readySince.set(id, now);
                }
            });

            const warmed = [];
            const pending = [];
            ready.forEach((id) => {
                if (synchronized.has(id)) {
                    return;
                }
                if (Number.isFinite(now) && now - readySince.get(id) >= warmupMs) {
                    warmed.push(id);
                } else {
                    pending.push(id);
                }
            });

            if (officialState === 'paused' && allowPausedStabilization) {
                warmed.forEach((id) => synchronized.add(id));
                warmed.forEach((id) => readySince.delete(id));
                changed = changed || warmed.length > 0;
            }

            return {
                activeIds: [...active],
                readyIds: [...ready],
                warmedIds: warmed,
                pendingIds: pending,
                changed,
                shouldResync: officialState === 'playing'
                    && ready.size >= 2
                    && warmed.length > 0
                    && Number.isFinite(now)
                    && now >= retryAfter,
            };
        };

        return {
            observe,
            readyParticipantIds: () => [...ready],
            synchronizedParticipantIds: () => [...synchronized],
            markSynchronized(ids) {
                let changed = false;
                ids.filter(validId).forEach((id) => {
                    if (!synchronized.has(id)) {
                        synchronized.add(id);
                        changed = true;
                    }
                    readySince.delete(id);
                });
                retryAfter = 0;
                return changed;
            },
            deferRetry(now) {
                retryAfter = Number.isFinite(now) ? now + retryDelayMs : retryAfter;
            },
        };
    };

    const runResyncSequence = async ({
        transmission,
        requestSnapshot,
        sendCommand,
        wait,
        currentContext,
    }) => {
        const plan = resyncPlan(transmission);
        if (plan === null) {
            return {ok: false, reason: 'invalid_state'};
        }

        const initialContext = currentContext();
        const snapshot = await requestSnapshot();
        if (!Number.isSafeInteger(snapshot?.positionMs)
            || snapshot.positionMs < 0
            || !resyncContextMatches(initialContext, currentContext())) {
            return {ok: false, reason: 'stale'};
        }

        const first = await sendCommand(plan.firstAction, snapshot.positionMs, initialContext);
        if (first?.ok !== true) {
            return {ok: false, reason: first?.reason ?? 'command_failed'};
        }
        if (plan.resumeAction === null) {
            return {ok: true};
        }

        const pausedContext = currentContext();
        await wait(SYNC_SETTLE_DELAY_MS);
        if (!resyncContextMatches(pausedContext, currentContext())) {
            return {ok: false, reason: 'stale'};
        }

        const resumePositionMs = plan.resumeAction === 'live'
            ? null
            : pausedContext.playbackPositionMs;
        const resumed = await sendCommand(plan.resumeAction, resumePositionMs, pausedContext);
        return resumed?.ok === true
            ? {ok: true}
            : {ok: false, reason: resumed?.reason ?? 'command_failed'};
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
                ? { kind: 'live-edge', current: 'AO VIVO', duration: '' }
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
        LIVE_SCRUB_THRESHOLD_MS,
        SYNC_SETTLE_DELAY_MS,
        AUTO_RESYNC_RETRY_DELAY_MS,
        DEFAULT_PLAYER_VOLUME,
        PARTICIPANT_SYNC_WARMUP_MS,
        PLAYER_VOLUME_STORAGE_KEY,
        behindLiveMs,
        createParticipantSyncTracker,
        createScrubbingSession,
        createResyncLock,
        formatTime,
        isNearLiveEdge,
        liveRangeMaxMs,
        livePositionLabel,
        liveSyncTargetMs,
        localOfficialDriftMs,
        playbackPresentation,
        participantSyncStorageKey,
        playerUnmuteVolume,
        playerVolumeSelection,
        normalizePlayerVolume,
        readStoredPlayerVolume,
        resyncContextMatches,
        resyncPlan,
        runResyncSequence,
        sharedPlaybackDispatchKey,
        shouldBootstrapLiveEdge,
        writeStoredPlayerVolume,
    };
});
