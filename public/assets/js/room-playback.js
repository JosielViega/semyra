'use strict';

(() => {
    const shell = document.querySelector('[data-room-shell]');
    const controls = document.querySelector('[data-shared-playback-controls]');
    const media = window.SemyraMedia;
    const debugEnabled = document.querySelector('[data-room-telemetry]') !== null;
    if (!shell || !controls || !media) {
        return;
    }

    const endpoint = shell.dataset.playbackUrl ?? '';
    const csrfToken = shell.dataset.csrfToken ?? '';
    const toggleButton = controls.querySelector('[data-playback-toggle]');
    const liveButton = controls.querySelector('[data-playback-live]');
    const seekInput = controls.querySelector('[data-playback-seek]');
    const currentOutput = controls.querySelector('[data-playback-current]');
    const separator = controls.querySelector('[data-playback-separator]');
    const durationOutput = controls.querySelector('[data-playback-duration]');
    const statusElement = controls.querySelector('[data-playback-status]');
    const maxTimeMs = 315576000000;
    let transmission = null;
    let durationMs = 0;
    let commandInProgress = false;
    const scrubbing = media.createScrubbingSession();
    let dispatchedKey = '';

    if (!endpoint || !csrfToken || !toggleButton || !liveButton || !seekInput
        || !currentOutput || !separator || !durationOutput) {
        return;
    }

    const emitMediaDebug = (detail) => {
        if (debugEnabled) {
            document.dispatchEvent(new CustomEvent('semyra:media-debug', {
                detail: { source: 'playback', ...detail },
            }));
        }
    };

    const setStatus = (message) => {
        if (statusElement) {
            statusElement.textContent = message;
        }
    };

    const normalizePublicTransmission = (value) => {
        if (value === null) {
            return null;
        }
        const source = value?.source;
        const videoId = value?.videoId ?? value?.youtube_video_id;
        const revision = value?.revision;
        const ownerName = value?.ownerName ?? value?.owner_name;
        const isOwner = value?.isOwner ?? value?.is_owner;
        const mediaMode = value?.mediaMode ?? value?.media_mode;
        const playback = value?.playback;
        const positionMs = playback?.positionMs ?? playback?.position_ms;
        const atLiveEdge = playback?.atLiveEdge ?? playback?.at_live_edge;
        const rawLiveEdge = playback?.liveEdgePositionMs !== undefined
            ? playback.liveEdgePositionMs
            : playback?.live_edge_position_ms;
        const liveEdgePositionMs = rawLiveEdge === null ? null : Number(rawLiveEdge);
        if (source !== 'youtube'
            || typeof videoId !== 'string'
            || !/^[A-Za-z0-9_-]{11}$/.test(videoId)
            || !Number.isSafeInteger(revision)
            || revision < 1
            || typeof ownerName !== 'string'
            || typeof isOwner !== 'boolean'
            || !['vod', 'live'].includes(mediaMode)
            || typeof atLiveEdge !== 'boolean'
            || (mediaMode !== 'live' && atLiveEdge)
            || !['playing', 'paused'].includes(playback?.state)
            || !Number.isSafeInteger(positionMs)
            || positionMs < 0
            || positionMs > maxTimeMs
            || (liveEdgePositionMs !== null
                && (!Number.isSafeInteger(liveEdgePositionMs)
                    || liveEdgePositionMs < 0
                    || liveEdgePositionMs > maxTimeMs))
            || !Number.isSafeInteger(playback?.revision)
            || playback.revision < 1) {
            return null;
        }

        return {
            source,
            videoId,
            revision,
            ownerName,
            isOwner,
            mediaMode,
            playback: {
                state: playback.state,
                positionMs,
                revision: playback.revision,
                atLiveEdge,
                liveEdgePositionMs,
            },
        };
    };

    const render = () => {
        const owner = transmission?.isOwner === true;
        controls.hidden = transmission === null;
        const positionMs = scrubbing.isActive()
            ? (scrubbing.positionMs() ?? transmission?.playback.positionMs ?? 0)
            : (transmission?.playback.positionMs ?? 0);
        const mediaMode = transmission?.mediaMode ?? 'vod';
        const liveEdgePositionMs = transmission?.playback.liveEdgePositionMs ?? null;
        const presentation = media.playbackPresentation({
            mediaMode,
            atLiveEdge: transmission?.playback.atLiveEdge ?? false,
            positionMs,
            durationMs,
            liveEdgeMs: liveEdgePositionMs,
        });
        const behindLiveMs = mediaMode === 'live'
            ? media.behindLiveMs(liveEdgePositionMs, positionMs)
            : null;
        emitMediaDebug({
            transmission,
            durationMs,
            liveEdgePositionMs,
            behindLiveMs,
            uiBranch: presentation.kind,
        });
        if (transmission === null) {
            return;
        }

        const playing = transmission.playback.state === 'playing';
        const live = mediaMode === 'live';
        const rangeMaxMs = live ? liveEdgePositionMs : durationMs;
        toggleButton.textContent = playing ? 'Ⅱ' : '▶';
        toggleButton.setAttribute('aria-label', playing ? 'Pausar transmissão' : 'Reproduzir transmissão');
        toggleButton.hidden = !owner;
        toggleButton.disabled = commandInProgress;
        seekInput.disabled = !owner || commandInProgress
            || !Number.isSafeInteger(rangeMaxMs) || rangeMaxMs <= 0;
        seekInput.max = String(Math.max(0, Math.floor((rangeMaxMs ?? 0) / 1000)));
        if (!scrubbing.isActive()) {
            seekInput.value = transmission.playback.atLiveEdge
                ? seekInput.max
                : String(Math.min(Math.floor(positionMs / 1000), Number(seekInput.max)));
        }

        separator.hidden = live;
        durationOutput.hidden = live;
        liveButton.hidden = !owner || !live || transmission.playback.atLiveEdge;
        liveButton.disabled = commandInProgress;
        currentOutput.textContent = presentation.current;
        durationOutput.textContent = presentation.duration;
    };

    const applyTransmission = (nextTransmission) => {
        const previousRevision = transmission?.revision ?? null;
        transmission = normalizePublicTransmission(nextTransmission);
        if (scrubbing.isActive() && transmission?.isOwner !== true) {
            scrubbing.cancel();
            document.dispatchEvent(new CustomEvent('semyra:hud-interaction-end'));
        }
        if (transmission?.revision !== previousRevision) {
            durationMs = 0;
        }
        render();
        if (transmission === null) {
            dispatchedKey = '';
            return;
        }

        const key = `${transmission.revision}:${transmission.playback.revision}`;
        if (key === dispatchedKey) {
            return;
        }
        dispatchedKey = key;
        document.dispatchEvent(new CustomEvent('semyra:shared-playback-updated', {
            detail: {
                transmissionRevision: transmission.revision,
                mediaMode: transmission.mediaMode,
                atLiveEdge: transmission.playback.atLiveEdge,
                state: transmission.playback.state,
                positionMs: transmission.playback.positionMs,
                playbackRevision: transmission.playback.revision,
            },
        }));
    };

    const requestSnapshot = () => new Promise((resolve) => {
        const receive = (event) => {
            window.clearTimeout(timeoutId);
            resolve(event.detail ?? null);
        };
        const timeoutId = window.setTimeout(() => {
            document.removeEventListener('semyra:player-snapshot', receive);
            resolve(null);
        }, 300);
        document.addEventListener('semyra:player-snapshot', receive, { once: true });
        document.dispatchEvent(new CustomEvent('semyra:player-snapshot-request'));
    });

    const sendCommand = async (action, requestedPositionMs = null) => {
        if (commandInProgress || transmission?.isOwner !== true) {
            return;
        }

        let positionMs = requestedPositionMs;
        if (action !== 'live' && positionMs === null) {
            const snapshot = await requestSnapshot();
            positionMs = snapshot?.positionMs;
        }
        if (action !== 'live'
            && (!Number.isSafeInteger(positionMs) || positionMs < 0 || positionMs > maxTimeMs)) {
            setStatus('Player ainda não está pronto.');
            return;
        }

        commandInProgress = true;
        render();
        setStatus('Atualizando…');
        try {
            const body = new URLSearchParams({
                _token: csrfToken,
                action,
                transmission_revision: String(transmission.revision),
                playback_revision: String(transmission.playback.revision),
            });
            if (action !== 'live') {
                body.set('position_ms', String(positionMs));
            }
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                credentials: 'same-origin',
                body,
            });
            const payload = await response.json();
            if (!response.ok) {
                if (payload.transmission !== undefined) {
                    applyTransmission(payload.transmission);
                }
                document.dispatchEvent(new CustomEvent('semyra:presence-refresh-request'));
                setStatus(response.status === 403
                    ? 'Somente quem está transmitindo pode controlar.'
                    : 'Estado atualizado por outra ação. Sincronizando…');
                return;
            }

            applyTransmission(payload.transmission ?? null);
            setStatus('Estado compartilhado atualizado.');
        } catch {
            setStatus('Não foi possível enviar o comando agora.');
        } finally {
            commandInProgress = false;
            render();
        }
    };

    toggleButton.addEventListener('click', () => {
        if (transmission !== null) {
            sendCommand(transmission.playback.state === 'playing' ? 'pause' : 'play');
        }
    });
    liveButton.addEventListener('click', () => sendCommand('live'));

    const beginScrubbing = () => {
        if (scrubbing.isActive()) {
            return true;
        }
        const started = scrubbing.start(
            Number(seekInput.value) * 1000,
            transmission?.isOwner === true && !seekInput.disabled,
        );
        if (started) {
            document.dispatchEvent(new CustomEvent('semyra:hud-interaction-start'));
        }
        return started;
    };

    const finishScrubbing = () => {
        const command = scrubbing.commit(
            transmission?.mediaMode ?? 'vod',
            transmission?.playback.liveEdgePositionMs ?? null,
        );
        if (command === null) {
            return;
        }
        document.dispatchEvent(new CustomEvent('semyra:hud-interaction-end'));
        render();
        sendCommand(command.action, command.positionMs);
    };

    const cancelScrubbing = () => {
        if (!scrubbing.isActive()) {
            return;
        }
        scrubbing.cancel();
        document.dispatchEvent(new CustomEvent('semyra:hud-interaction-end'));
        render();
    };

    seekInput.addEventListener('input', () => {
        if (!beginScrubbing()) {
            return;
        }
        scrubbing.update(Number(seekInput.value) * 1000);
        if (transmission?.mediaMode === 'live') {
            currentOutput.textContent = media.livePositionLabel(
                transmission.playback.liveEdgePositionMs,
                Number(seekInput.value) * 1000,
            );
        } else {
            currentOutput.textContent = media.formatTime(Number(seekInput.value) * 1000);
        }
    });
    seekInput.addEventListener('change', finishScrubbing);
    ['pointerdown', 'touchstart'].forEach((eventName) => {
        seekInput.addEventListener(eventName, beginScrubbing, { passive: true });
    });
    ['pointerup', 'touchend'].forEach((eventName) => {
        seekInput.addEventListener(eventName, finishScrubbing);
    });
    seekInput.addEventListener('pointercancel', cancelScrubbing);
    seekInput.addEventListener('blur', cancelScrubbing);
    seekInput.addEventListener('keydown', (event) => {
        if (['ArrowLeft', 'ArrowRight', 'Home', 'End', 'PageUp', 'PageDown'].includes(event.key)) {
            beginScrubbing();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            cancelScrubbing();
        }
    });

    document.addEventListener('semyra:player-telemetry', (event) => {
        if (transmission?.mediaMode === 'vod'
            && Number.isSafeInteger(event.detail?.durationMs)
            && event.detail.durationMs >= 0) {
            durationMs = event.detail.durationMs;
            render();
        }
    });
    document.addEventListener('semyra:player-ended', (event) => {
        if (transmission?.isOwner === true && transmission.playback.state === 'playing') {
            const finalPosition = transmission.mediaMode === 'live'
                ? event.detail?.positionMs
                : (Number.isSafeInteger(event.detail?.durationMs)
                    ? event.detail.durationMs
                    : event.detail?.positionMs);
            sendCommand('pause', finalPosition);
        }
    });
    document.addEventListener('semyra:presence-updated', (event) => {
        applyTransmission(event.detail?.transmission ?? null);
    });

    const initialRevision = Number(shell.dataset.initialRevision);
    const rawInitialLiveEdge = shell.dataset.initialLiveEdgePositionMs;
    applyTransmission(Number.isSafeInteger(initialRevision) && initialRevision > 0
        ? {
            source: shell.dataset.initialSource,
            videoId: shell.dataset.initialVideoId,
            revision: initialRevision,
            ownerName: shell.dataset.initialOwnerName || 'Participante',
            isOwner: shell.dataset.initialIsOwner === '1',
            mediaMode: shell.dataset.initialMediaMode,
            playback: {
                state: shell.dataset.initialPlaybackState,
                positionMs: Number(shell.dataset.initialPlaybackPositionMs),
                revision: Number(shell.dataset.initialPlaybackRevision),
                atLiveEdge: shell.dataset.initialPlaybackAtLiveEdge === '1',
                liveEdgePositionMs: rawInitialLiveEdge === '' ? null : Number(rawInitialLiveEdge),
            },
        }
        : null);
})();
