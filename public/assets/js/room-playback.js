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
    const syncButton = controls.querySelector('[data-playback-sync]');
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
    let autoCheckInProgress = false;
    const resyncLock = media.createResyncLock();
    let participantSyncTracker = null;
    let participantSyncRevision = null;
    let latestParticipants = [];
    const scrubbing = media.createScrubbingSession();
    let dispatchedKey = '';

    if (!endpoint || !csrfToken || !syncButton || !toggleButton || !liveButton || !seekInput
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

    const controlsLocked = () => commandInProgress || resyncLock.active();

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
        const positionMs = playback?.positionMs !== undefined
            ? playback.positionMs
            : playback?.position_ms;
        const atLiveEdge = playback?.atLiveEdge ?? playback?.at_live_edge;
        const rawLiveEdge = playback?.liveEdgePositionMs !== undefined
            ? playback.liveEdgePositionMs
            : playback?.live_edge_position_ms;
        const rawLiveSync = playback?.liveSyncPositionMs !== undefined
            ? playback.liveSyncPositionMs
            : playback?.live_sync_position_ms;
        const rawLiveSyncDelay = playback?.liveSyncDelayMs !== undefined
            ? playback.liveSyncDelayMs
            : playback?.live_sync_delay_ms;
        const liveEdgePositionMs = rawLiveEdge === null ? null : Number(rawLiveEdge);
        const liveSyncPositionMs = rawLiveSync === null ? null : Number(rawLiveSync);
        const liveSyncDelayMs = rawLiveSyncDelay === null ? null : Number(rawLiveSyncDelay);
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
            || (positionMs !== null
                && (!Number.isSafeInteger(positionMs) || positionMs < 0 || positionMs > maxTimeMs))
            || (positionMs === null
                && !(mediaMode === 'live' && atLiveEdge && liveSyncPositionMs === null))
            || (liveEdgePositionMs !== null
                && (!Number.isSafeInteger(liveEdgePositionMs)
                    || liveEdgePositionMs < 0
                    || liveEdgePositionMs > maxTimeMs))
            || (liveSyncPositionMs !== null
                && (!Number.isSafeInteger(liveSyncPositionMs)
                    || liveSyncPositionMs < 0
                    || liveSyncPositionMs > maxTimeMs))
            || (mediaMode === 'live'
                && (!Number.isSafeInteger(liveSyncDelayMs) || liveSyncDelayMs < 0))
            || (mediaMode !== 'live'
                && (liveEdgePositionMs !== null
                    || liveSyncPositionMs !== null
                    || liveSyncDelayMs !== null))
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
                liveSyncPositionMs,
                liveSyncDelayMs,
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
        const liveSyncPositionMs = transmission?.playback.liveSyncPositionMs ?? null;
        const presentation = media.playbackPresentation({
            mediaMode,
            atLiveEdge: transmission?.playback.atLiveEdge ?? false,
            positionMs,
            durationMs,
            liveEdgeMs: liveSyncPositionMs,
        });
        const behindLiveMs = mediaMode === 'live'
            ? media.behindLiveMs(liveSyncPositionMs, positionMs)
            : null;
        emitMediaDebug({
            transmission,
            durationMs,
            liveEdgePositionMs,
            liveSyncPositionMs,
            liveSyncDelayMs: transmission?.playback.liveSyncDelayMs ?? null,
            officialPositionMs: transmission?.playback.positionMs ?? null,
            officialState: transmission?.playback.state ?? null,
            behindLiveMs,
            uiBranch: presentation.kind,
        });
        if (transmission === null) {
            return;
        }

        const playing = transmission.playback.state === 'playing';
        const live = mediaMode === 'live';
        const rangeMaxMs = live ? media.liveRangeMaxMs(liveSyncPositionMs) : durationMs;
        syncButton.hidden = !owner;
        syncButton.disabled = controlsLocked();
        toggleButton.textContent = playing ? 'Ⅱ' : '▶';
        toggleButton.setAttribute('aria-label', playing ? 'Pausar transmissão' : 'Reproduzir transmissão');
        toggleButton.hidden = !owner;
        toggleButton.disabled = controlsLocked();
        seekInput.disabled = !owner || controlsLocked()
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
        liveButton.disabled = controlsLocked();
        currentOutput.textContent = presentation.current;
        durationOutput.textContent = presentation.duration;
    };

    const applyTransmission = (nextTransmission) => {
        const previousRevision = transmission?.revision ?? null;
        const wasOwner = transmission?.isOwner === true;
        transmission = normalizePublicTransmission(nextTransmission);
        if (scrubbing.isActive() && transmission?.isOwner !== true) {
            scrubbing.cancel();
            document.dispatchEvent(new CustomEvent('semyra:hud-interaction-end'));
        }
        if (transmission?.revision !== previousRevision) {
            durationMs = 0;
        }
        if (transmission?.revision !== previousRevision
            || (transmission?.isOwner === true) !== wasOwner) {
            resetParticipantSync(previousRevision);
        }
        render();
        if (transmission === null) {
            dispatchedKey = '';
            return;
        }

        const key = media.sharedPlaybackDispatchKey(
            transmission.revision,
            transmission.playback.revision,
            transmission.playback.liveSyncPositionMs,
        );
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
                liveEdgePositionMs: transmission.playback.liveEdgePositionMs,
                liveSyncPositionMs: transmission.playback.liveSyncPositionMs,
                liveSyncDelayMs: transmission.playback.liveSyncDelayMs,
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

    const currentContext = () => transmission === null ? null : {
        isOwner: transmission.isOwner,
        transmissionRevision: transmission.revision,
        playbackRevision: transmission.playback.revision,
        playbackPositionMs: transmission.playback.positionMs,
    };

    const performCommand = async (action, positionMs, expectedContext) => {
        if (!media.resyncContextMatches(expectedContext, currentContext())) {
            return {ok: false, reason: 'stale'};
        }

        if (action !== 'live'
            && (!Number.isSafeInteger(positionMs) || positionMs < 0 || positionMs > maxTimeMs)) {
            return {ok: false, reason: 'player_not_ready'};
        }

        try {
            const body = new URLSearchParams({
                _token: csrfToken,
                action,
                transmission_revision: String(expectedContext.transmissionRevision),
                playback_revision: String(expectedContext.playbackRevision),
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
                return {ok: false, reason: response.status === 403 ? 'owner_lost' : 'conflict'};
            }

            applyTransmission(payload.transmission ?? null);
            const confirmed = currentContext();
            if (confirmed === null
                || confirmed.isOwner !== true
                || confirmed.transmissionRevision !== expectedContext.transmissionRevision
                || confirmed.playbackRevision !== expectedContext.playbackRevision + 1) {
                return {ok: false, reason: 'invalid_confirmation'};
            }
            return {ok: true};
        } catch {
            return {ok: false, reason: 'network'};
        }
    };

    const sendCommand = async (action, requestedPositionMs = null) => {
        if (controlsLocked() || transmission?.isOwner !== true) {
            return {ok: false, reason: 'busy'};
        }

        const expectedContext = currentContext();
        let positionMs = requestedPositionMs;
        if (action !== 'live' && positionMs === null) {
            const snapshot = await requestSnapshot();
            positionMs = snapshot?.positionMs;
        }
        if (!media.resyncContextMatches(expectedContext, currentContext())) {
            return {ok: false, reason: 'stale'};
        }

        commandInProgress = true;
        render();
        setStatus('Atualizando…');
        const result = await performCommand(action, positionMs, expectedContext);
        commandInProgress = false;
        render();
        if (result.ok) {
            setStatus('Estado compartilhado atualizado.');
        } else if (result.reason === 'player_not_ready') {
            setStatus('Player ainda não está pronto.');
        } else if (result.reason === 'owner_lost') {
            setStatus('Somente quem está transmitindo pode controlar.');
        } else {
            setStatus('Não foi possível enviar o comando agora.');
        }
        return result;
    };

    const participantStorageKey = (revision) => media.participantSyncStorageKey(endpoint, revision);
    const storedParticipantIds = (revision) => {
        try {
            const stored = window.sessionStorage.getItem(participantStorageKey(revision));
            const value = JSON.parse(stored ?? '[]');
            return Array.isArray(value) ? value : [];
        } catch {
            return [];
        }
    };
    const persistParticipantSync = () => {
        if (participantSyncTracker === null || participantSyncRevision === null) {
            return;
        }
        try {
            window.sessionStorage.setItem(
                participantStorageKey(participantSyncRevision),
                JSON.stringify(participantSyncTracker.synchronizedParticipantIds()),
            );
        } catch {
            // In-memory tracking remains authoritative for the current page.
        }
    };
    const resetParticipantSync = (previousRevision) => {
        if (Number.isSafeInteger(previousRevision)
            && (previousRevision !== transmission?.revision
                || transmission?.isOwner !== true)) {
            try {
                window.sessionStorage.removeItem(participantStorageKey(previousRevision));
            } catch {
                // Storage cleanup is best-effort.
            }
        }
        participantSyncRevision = transmission?.isOwner === true ? transmission.revision : null;
        participantSyncTracker = participantSyncRevision === null
            ? null
            : media.createParticipantSyncTracker({
                synchronizedIds: storedParticipantIds(participantSyncRevision),
            });
    };

    const wait = (milliseconds) => new Promise((resolve) => {
        window.setTimeout(resolve, milliseconds);
    });

    const startResync = async ({automatic = false, initialSnapshot = null} = {}) => {
        if (commandInProgress || transmission?.isOwner !== true || !resyncLock.begin()) {
            return false;
        }

        const revision = transmission.revision;
        const coveredParticipantIds = participantSyncTracker?.readyParticipantIds() ?? [];
        let snapshot = initialSnapshot;
        render();
        document.dispatchEvent(new CustomEvent('semyra:hud-interaction-start'));
        setStatus(automatic ? 'Sincronizando novo participante…' : 'Sincronizando…');
        let result = {ok: false, reason: 'unexpected'};
        try {
            result = await media.runResyncSequence({
                transmission: {
                    mediaMode: transmission.mediaMode,
                    state: transmission.playback.state,
                    atLiveEdge: transmission.playback.atLiveEdge,
                },
                requestSnapshot: async () => {
                    if (snapshot !== null) {
                        const provided = snapshot;
                        snapshot = null;
                        return provided;
                    }
                    return requestSnapshot();
                },
                sendCommand: performCommand,
                wait,
                currentContext,
            });
        } finally {
            resyncLock.end();
            render();
            document.dispatchEvent(new CustomEvent('semyra:hud-interaction-end'));
        }
        if (result.ok) {
            if (transmission?.revision === revision && participantSyncRevision === revision) {
                participantSyncTracker?.markSynchronized(coveredParticipantIds);
                persistParticipantSync();
            }
            setStatus('Sincronizado.');
            return true;
        }

        setStatus('Não foi possível sincronizar agora.');
        if (automatic && transmission?.revision === revision
            && participantSyncRevision === revision) {
            participantSyncTracker?.deferRetry(Date.now());
            document.dispatchEvent(new CustomEvent('semyra:presence-refresh-request'));
        }
        return false;
    };

    const maybeAutoResync = async (participants) => {
        const revision = transmission?.revision;
        latestParticipants = Array.isArray(participants) ? participants : [];
        if (!Number.isSafeInteger(revision)
            || autoCheckInProgress
            || transmission?.isOwner !== true
            || participantSyncTracker === null
            || participantSyncRevision !== revision) {
            return;
        }

        const decision = participantSyncTracker.observe({
            participants: latestParticipants,
            now: Date.now(),
            officialState: transmission.playback.state,
            allowPausedStabilization: !controlsLocked() && !autoCheckInProgress,
        });
        if (decision.changed) {
            persistParticipantSync();
        }
        if (!decision.shouldResync
            || controlsLocked()
            || (transmission.mediaMode === 'live'
                && transmission.playback.atLiveEdge
                && !Number.isSafeInteger(transmission.playback.liveSyncPositionMs))) {
            return;
        }

        autoCheckInProgress = true;
        try {
            const expectedContext = currentContext();
            const snapshot = await requestSnapshot();
            if (Number.isSafeInteger(snapshot?.positionMs)
                && media.resyncContextMatches(expectedContext, currentContext())
                && !controlsLocked()) {
                await startResync({automatic: true, initialSnapshot: snapshot});
            } else if (participantSyncRevision === revision) {
                participantSyncTracker?.deferRetry(Date.now());
            }
        } finally {
            autoCheckInProgress = false;
        }
    };

    toggleButton.addEventListener('click', () => {
        if (transmission !== null) {
            sendCommand(transmission.playback.state === 'playing' ? 'pause' : 'play');
        }
    });
    syncButton.addEventListener('click', () => startResync());
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
            transmission?.playback.liveSyncPositionMs ?? null,
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
                transmission.playback.liveSyncPositionMs,
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
        seekInput.addEventListener(eventName, () => window.setTimeout(finishScrubbing, 100));
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
    seekInput.addEventListener('keyup', (event) => {
        if (['ArrowLeft', 'ArrowRight', 'Home', 'End', 'PageUp', 'PageDown'].includes(event.key)) {
            finishScrubbing();
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
        maybeAutoResync(event.detail?.participants ?? []);
    });

    const initialRevision = Number(shell.dataset.initialRevision);
    const rawInitialLiveEdge = shell.dataset.initialLiveEdgePositionMs;
    const rawInitialLiveSync = shell.dataset.initialLiveSyncPositionMs;
    const rawInitialLiveSyncDelay = shell.dataset.initialLiveSyncDelayMs;
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
                liveSyncPositionMs: rawInitialLiveSync === '' ? null : Number(rawInitialLiveSync),
                liveSyncDelayMs: rawInitialLiveSyncDelay === '' ? null : Number(rawInitialLiveSyncDelay),
            },
        }
        : null);
})();
