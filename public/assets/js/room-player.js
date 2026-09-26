'use strict';

(() => {
    const mount = document.getElementById('room-player-mount');
    if (!mount) {
        return;
    }

    const statusElement = document.getElementById('youtube-player-status');
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
    let currentIsOwner = false;
    let endedReportedRevision = null;
    let apiPromise = null;

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
        statusElement.textContent = message;
        statusElement.classList.toggle('is-error', isError);
    };

    const emitMutedState = () => {
        if (!playerReady || player === null || typeof player.isMuted !== 'function') {
            return;
        }
        document.dispatchEvent(new CustomEvent('semyra:player-muted-state', {
            detail: { muted: player.isMuted() },
        }));
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
        if (!playerReady
            || player === null
            || playback === null
            || playback.transmissionRevision !== currentRevision
            || (!force && playback.playbackRevision === appliedPlaybackRevision)) {
            return;
        }

        try {
            if (playback.mediaMode === 'live' && playback.atLiveEdge) {
                if (appliedPlaybackRevision === null) {
                    // A newly loaded Live stays at YouTube's natural live position.
                    player.playVideo();
                } else {
                    // Returning to Live reloads the same media without startSeconds.
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
            || !Number.isSafeInteger(playback?.positionMs)
            || playback.positionMs < 0
            || playback.positionMs > maxTimeMs
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
                        player.mute();
                        emitMutedState();
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
        if (transmission === null) {
            pendingTransmission = null;
            pendingSharedPlayback = null;
            currentRevision = null;
            currentVideoId = null;
            appliedPlaybackRevision = null;
            currentIsOwner = false;
            destroyPlayer();
            updateStatus('');
            return;
        }
        if (transmission.source !== 'youtube'
            || !videoIdPattern.test(transmission.videoId)
            || !Number.isSafeInteger(transmission.revision)
            || transmission.revision < 1) {
            return;
        }

        currentIsOwner = transmission.isOwner === true;
        receiveSharedPlayback({
            transmissionRevision: transmission.revision,
            mediaMode: transmission.mediaMode,
            atLiveEdge: transmission.playback?.atLiveEdge,
            state: transmission.playback?.state,
            positionMs: transmission.playback?.positionMs,
            playbackRevision: transmission.playback?.revision,
        });
        if (transmission.revision === currentRevision) {
            return;
        }

        pendingTransmission = transmission;
        currentRevision = transmission.revision;
        currentVideoId = transmission.videoId;
        appliedPlaybackRevision = null;
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
            if (player.isMuted()) {
                player.unMute();
            } else {
                player.mute();
            }
            emitMutedState();
        } catch {
            // Local audio control is optional and must not interrupt presence.
        }
    });
})();
