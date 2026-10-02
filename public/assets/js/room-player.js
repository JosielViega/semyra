'use strict';

(() => {
    const mount = document.querySelector('[data-youtube-player-mount]');
    if (!mount) {
        return;
    }

    const statusElement = document.getElementById('youtube-player-status');
    const media = window.SemyraMedia;
    if (!media) {
        return;
    }
    const debugEnabled = document.querySelector('[data-room-telemetry]') !== null;
    const apiUrl = 'https://www.youtube.com/iframe_api';
    const videoIdPattern = /^[A-Za-z0-9_-]{11}$/;
    const allowedStates = new Set([-1, 0, 1, 2, 3, 5]);
    const maxTimeMs = 315576000000;
    let player = null;
    let playerReady = false;
    let currentRevision = null;
    let currentVideoId = null;
    let pendingTransmission = null;
    let pendingSharedPlayback = null;
    let appliedPlaybackRevision = null;
    let appliedAnchorReady = false;
    let currentIsOwner = false;
    let endedReportedRevision = null;
    let apiPromise = null;
    let volumeStorage = null;
    try {
        volumeStorage = window.localStorage;
    } catch {
        // Storage can be unavailable in privacy-restricted browsing contexts.
    }
    let selectedVolume = media.readStoredPlayerVolume(volumeStorage);
    let restoreVolume = selectedVolume > 0 ? selectedVolume : media.DEFAULT_PLAYER_VOLUME;
    let locallyMuted = true;
    let audioPreferenceTouched = false;

    const emitMediaDebug = (detail) => {
        if (debugEnabled) {
            document.dispatchEvent(new CustomEvent('semyra:media-debug', {
                detail: { source: 'player', ...detail },
            }));
        }
    };

    const updateStatus = (message, isError = false) => {
        if (!statusElement) {
            return;
        }
        statusElement.hidden = message === '';
        statusElement.textContent = message;
        statusElement.classList.toggle('is-error', isError);
    };

    const emitAudioState = () => {
        if (!playerReady
            || player === null
            || typeof player.isMuted !== 'function'
            || typeof player.getVolume !== 'function') {
            return;
        }
        try {
            const muted = audioPreferenceTouched
                ? locallyMuted
                : locallyMuted || player.isMuted();
            const volume = audioPreferenceTouched
                ? selectedVolume
                : media.normalizePlayerVolume(player.getVolume(), selectedVolume);
            document.dispatchEvent(new CustomEvent('semyra:player-audio-state', {
                detail: {
                    muted,
                    volume,
                },
            }));
        } catch {
            // Local audio state is optional and must not interrupt playback.
        }
    };

    const applyLocalAudioPreference = () => {
        if (!playerReady || player === null) {
            return;
        }
        try {
            player.setVolume(selectedVolume);
            if (audioPreferenceTouched && !locallyMuted && selectedVolume > 0) {
                player.unMute();
            } else {
                player.mute();
                locallyMuted = true;
            }
            emitAudioState();
            window.setTimeout(emitAudioState, 0);
        } catch {
            // YouTube audio APIs are a progressive local enhancement.
        }
    };

    const selectLocalVolume = (value) => {
        if (!playerReady || player === null) {
            return;
        }
        const selection = media.playerVolumeSelection(value, restoreVolume);
        selectedVolume = selection.volume;
        restoreVolume = selection.restoreVolume;
        locallyMuted = selection.muted;
        audioPreferenceTouched = true;
        media.writeStoredPlayerVolume(volumeStorage, selectedVolume);

        try {
            player.setVolume(selectedVolume);
            if (selection.muted) {
                player.mute();
            } else {
                player.unMute();
            }
            emitAudioState();
            window.setTimeout(emitAudioState, 0);
        } catch {
            // Local audio control is optional and must not interrupt playback.
        }
    };

    const readSnapshot = () => {
        if (!playerReady || player === null) {
            return null;
        }

        try {
            const state = player.getPlayerState();
            const positionMs = Math.round(player.getCurrentTime() * 1000);
            const durationMs = Math.round(player.getDuration() * 1000);
            if (!Number.isInteger(state)
                || !allowedStates.has(state)
                || !Number.isSafeInteger(positionMs)
                || positionMs < 0
                || positionMs > maxTimeMs
                || !Number.isSafeInteger(durationMs)
                || durationMs < 0
                || durationMs > maxTimeMs) {
                return null;
            }

            return { state, positionMs, durationMs };
        } catch {
            return null;
        }
    };

    const emitTelemetry = () => {
        const snapshot = readSnapshot();
        if (snapshot !== null) {
            document.dispatchEvent(new CustomEvent('semyra:player-telemetry', { detail: snapshot }));
            emitMediaDebug({ playerReady: true, playerState: snapshot.state, snapshot });
        }
    };

    const emitSnapshot = () => {
        const snapshot = readSnapshot();
        if (snapshot !== null) {
            document.dispatchEvent(new CustomEvent('semyra:player-snapshot', { detail: snapshot }));
        }
    };

    const destroyPlayer = () => {
        playerReady = false;
        emitMediaDebug({ playerReady: false });
        if (player !== null && typeof player.destroy === 'function') {
            try {
                player.destroy();
            } catch {
                // The mount is reset below even if YouTube cleanup fails.
            }
        }
        player = null;
        mount.replaceChildren();
    };

    const applySharedPlayback = (force = false) => {
        const playback = pendingSharedPlayback;
        const anchorReady = Number.isSafeInteger(playback?.liveSyncPositionMs);
        if (!playerReady
            || player === null
            || playback === null
            || playback.transmissionRevision !== currentRevision
            || (!force
                && playback.playbackRevision === appliedPlaybackRevision
                && anchorReady === appliedAnchorReady)) {
            return;
        }

        try {
            if (playback.mediaMode === 'live' && playback.atLiveEdge) {
                if (anchorReady) {
                    player.seekTo(playback.liveSyncPositionMs / 1000, true);
                    player.playVideo();
                } else if (appliedPlaybackRevision === null) {
                    // A newly loaded Live stays at YouTube's natural live position.
                    player.playVideo();
                } else {
                    // Without an anchor, returning to Live uses YouTube's natural position.
                    player.loadVideoById({ videoId: currentVideoId });
                }
            } else {
                player.seekTo(playback.positionMs / 1000, true);
                if (playback.state === 'playing') {
                    player.playVideo();
                } else {
                    player.pauseVideo();
                }
            }
            appliedPlaybackRevision = playback.playbackRevision;
            appliedAnchorReady = anchorReady;
            endedReportedRevision = null;
            emitTelemetry();
            updateStatus(playback.mediaMode === 'live' && playback.atLiveEdge
                ? 'Acompanhando ao vivo.'
                : (playback.state === 'playing' ? 'Reprodução sincronizada.' : 'Transmissão pausada.'));
        } catch {
            updateStatus('Não foi possível aplicar o estado compartilhado agora.', true);
        }
    };

    const receiveSharedPlayback = (playback) => {
        if (!Number.isSafeInteger(playback?.transmissionRevision)
            || playback.transmissionRevision < 1
            || !['vod', 'live'].includes(playback?.mediaMode)
            || typeof playback?.atLiveEdge !== 'boolean'
            || (playback.mediaMode !== 'live' && playback.atLiveEdge)
            || !['playing', 'paused'].includes(playback?.state)
            || (playback?.positionMs !== null
                && (!Number.isSafeInteger(playback.positionMs)
                    || playback.positionMs < 0
                    || playback.positionMs > maxTimeMs))
            || (playback?.positionMs === null
                && !(playback.mediaMode === 'live'
                    && playback.atLiveEdge
                    && playback.liveSyncPositionMs === null))
            || (playback?.liveSyncPositionMs !== null
                && (!Number.isSafeInteger(playback.liveSyncPositionMs)
                    || playback.liveSyncPositionMs < 0
                    || playback.liveSyncPositionMs > maxTimeMs))
            || !Number.isSafeInteger(playback?.playbackRevision)
            || playback.playbackRevision < 1) {
            return;
        }

        pendingSharedPlayback = playback;
        applySharedPlayback();
    };

    const messageForError = (code) => {
        if (code === 100) {
            return 'Este vídeo não está disponível.';
        }
        if (code === 101 || code === 150) {
            return 'Este vídeo não permite reprodução fora do YouTube.';
        }
        return 'Não foi possível carregar a transmissão do YouTube.';
    };

    const ensureApi = () => {
        if (window.YT && typeof window.YT.Player === 'function') {
            return Promise.resolve();
        }
        if (apiPromise !== null) {
            return apiPromise;
        }

        apiPromise = new Promise((resolve, reject) => {
            const previousReadyHandler = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = () => {
                if (typeof previousReadyHandler === 'function') {
                    previousReadyHandler();
                }
                resolve();
            };

            const existingScript = document.querySelector(`script[src="${apiUrl}"]`);
            if (existingScript) {
                existingScript.addEventListener('error', reject, { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = apiUrl;
            script.async = true;
            script.addEventListener('error', reject, { once: true });
            document.head.append(script);
        });

        return apiPromise;
    };

    const createPlayer = (transmission) => {
        if (!window.YT || typeof window.YT.Player !== 'function') {
            return;
        }

        destroyPlayer();
        const target = document.createElement('div');
        target.id = 'youtube-player';
        mount.append(target);

        try {
            player = new window.YT.Player(target, {
                width: '100%',
                height: '100%',
                videoId: transmission.videoId,
                playerVars: {
                    autoplay: 1,
                    controls: 0,
                    disablekb: 1,
                    enablejsapi: 1,
                    fs: 0,
                    playsinline: 1,
                    origin: window.location.origin,
                },
                events: {
                    onReady: (event) => {
                        player = event.target;
                        playerReady = true;
                        applyLocalAudioPreference();
                        emitMediaDebug({ playerReady: true, snapshot: readSnapshot() });
                        emitTelemetry();
                        applySharedPlayback(true);
                    },
                    onStateChange: (event) => {
                        emitTelemetry();
                        emitMediaDebug({
                            playerReady: true,
                            playerState: Number.isInteger(event.data) ? event.data : null,
                            snapshot: readSnapshot(),
                        });
                        if (event.data === 0
                            && currentIsOwner
                            && endedReportedRevision !== appliedPlaybackRevision) {
                            const snapshot = readSnapshot();
                            if (snapshot !== null) {
                                endedReportedRevision = appliedPlaybackRevision;
                                document.dispatchEvent(new CustomEvent('semyra:player-ended', {
                                    detail: snapshot,
                                }));
                            }
                        }
                    },
                    onError: (event) => updateStatus(messageForError(event.data), true),
                },
            });
        } catch {
            updateStatus('Não foi possível carregar a transmissão do YouTube.', true);
        }
    };

    const applyTransmission = async (transmission) => {
        if (transmission === null || transmission.source !== 'youtube') {
            pendingTransmission = null;
            pendingSharedPlayback = null;
            currentRevision = null;
            currentVideoId = null;
            appliedPlaybackRevision = null;
            appliedAnchorReady = false;
            currentIsOwner = false;
            destroyPlayer();
            mount.hidden = true;
            updateStatus('');
            return;
        }
        if (!videoIdPattern.test(transmission.videoId)
            || !Number.isSafeInteger(transmission.revision)
            || transmission.revision < 1) {
            return;
        }

        mount.hidden = false;
        currentIsOwner = transmission.isOwner === true;
        receiveSharedPlayback({
            transmissionRevision: transmission.revision,
            mediaMode: transmission.mediaMode,
            atLiveEdge: transmission.playback?.atLiveEdge,
            state: transmission.playback?.state,
            positionMs: transmission.playback?.positionMs,
            playbackRevision: transmission.playback?.revision,
            liveEdgePositionMs: transmission.playback?.liveEdgePositionMs,
            liveSyncPositionMs: transmission.playback?.liveSyncPositionMs,
            liveSyncDelayMs: transmission.playback?.liveSyncDelayMs,
        });
        if (transmission.revision === currentRevision) {
            return;
        }

        pendingTransmission = transmission;
        currentRevision = transmission.revision;
        currentVideoId = transmission.videoId;
        appliedPlaybackRevision = null;
        appliedAnchorReady = false;
        updateStatus('Carregando transmissão…');
        try {
            await ensureApi();
            if (pendingTransmission?.revision === transmission.revision) {
                createPlayer(transmission);
            }
        } catch {
            updateStatus('Não foi possível carregar o player do YouTube. Verifique sua conexão.', true);
        }
    };

    document.addEventListener('semyra:transmission-updated', (event) => {
        applyTransmission(event.detail?.transmission ?? null);
    });
    document.addEventListener('semyra:shared-playback-updated', (event) => {
        receiveSharedPlayback(event.detail);
    });
    document.addEventListener('semyra:player-telemetry-request', emitTelemetry);
    document.addEventListener('semyra:player-snapshot-request', emitSnapshot);
    document.addEventListener('semyra:player-mute-toggle', () => {
        if (!playerReady || player === null) {
            return;
        }
        try {
            if (locallyMuted || player.isMuted()) {
                selectedVolume = media.playerUnmuteVolume(selectedVolume, restoreVolume);
                restoreVolume = selectedVolume;
                media.writeStoredPlayerVolume(volumeStorage, selectedVolume);
                player.setVolume(selectedVolume);
                player.unMute();
                locallyMuted = false;
            } else {
                player.mute();
                locallyMuted = true;
            }
            audioPreferenceTouched = true;
            emitAudioState();
            window.setTimeout(emitAudioState, 0);
        } catch {
            // Local audio control is optional and must not interrupt presence.
        }
    });
    document.addEventListener('semyra:player-volume-change', (event) => {
        selectLocalVolume(event.detail?.volume);
    });
})();
