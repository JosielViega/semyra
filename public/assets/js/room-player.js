'use strict';

(() => {
    const mount = document.getElementById('room-player-mount');
    if (!mount) {
        return;
    }

    const statusElement = document.getElementById('youtube-player-status');
    const apiUrl = 'https://www.youtube.com/iframe_api';
    const videoIdPattern = /^[A-Za-z0-9_-]{11}$/;
    const allowedStates = new Set([-1, 0, 1, 2, 3, 5]);
    const maxTimeMs = 315576000000;
    let player = null;
    let playerReady = false;
    let currentRevision = null;
    let pendingTransmission = null;
    let apiPromise = null;

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

    const emitTelemetry = () => {
        if (!playerReady || player === null) {
            return;
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
                return;
            }

            document.dispatchEvent(new CustomEvent('semyra:player-telemetry', {
                detail: { state, positionMs, durationMs },
            }));
        } catch {
            // Presence remains available if the embedded player cannot be read.
        }
    };

    const destroyPlayer = () => {
        playerReady = false;
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
                        player.playVideo();
                        emitMutedState();
                        emitTelemetry();
                        updateStatus('Player pronto. Reprodução local iniciada sem som.');
                    },
                    onStateChange: emitTelemetry,
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
            currentRevision = null;
            destroyPlayer();
            updateStatus('');
            return;
        }
        if (transmission.source !== 'youtube'
            || !videoIdPattern.test(transmission.videoId)
            || !Number.isSafeInteger(transmission.revision)
            || transmission.revision < 1
            || transmission.revision === currentRevision) {
            return;
        }

        pendingTransmission = transmission;
        currentRevision = transmission.revision;
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
    document.addEventListener('semyra:player-telemetry-request', emitTelemetry);
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
