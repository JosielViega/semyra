'use strict';

(() => {
    const telemetryContainer = document.querySelector('[data-room-telemetry]');
    if (!telemetryContainer) {
        return;
    }

    const telemetryList = document.getElementById('room-telemetry-list');
    if (!telemetryList) {
        return;
    }

    const mediaDebug = {
        mediaMode: 'unknown',
        atLiveEdge: false,
        transmissionRevision: null,
        playbackRevision: null,
        playerReady: false,
        playerState: null,
        positionMs: null,
        durationMs: null,
        liveEdgePositionMs: null,
        behindLiveMs: null,
        uiBranch: 'preparing',
    };

    const debugFields = {
        mediaMode: document.querySelector('[data-debug-media-mode]'),
        atLiveEdge: document.querySelector('[data-debug-at-live-edge]'),
        transmissionRevision: document.querySelector('[data-debug-transmission-revision]'),
        playbackRevision: document.querySelector('[data-debug-playback-revision]'),
        playerReady: document.querySelector('[data-debug-player-ready]'),
        playerState: document.querySelector('[data-debug-player-state]'),
        currentTime: document.querySelector('[data-debug-current-time]'),
        duration: document.querySelector('[data-debug-duration]'),
        liveEdge: document.querySelector('[data-debug-live-edge]'),
        behindLive: document.querySelector('[data-debug-behind-live]'),
        uiBranch: document.querySelector('[data-debug-ui-branch]'),
    };

    const stateLabels = new Map([
        [-1, 'Não iniciado'],
        [0, 'Finalizado'],
        [1, 'Reproduzindo'],
        [2, 'Pausado'],
        [3, 'Bufferizando'],
        [5, 'Preparado'],
    ]);

    const formatTime = (milliseconds) => {
        const totalTenths = Math.round(milliseconds / 100);
        const tenths = totalTenths % 10;
        const totalSeconds = Math.floor(totalTenths / 10);
        const seconds = totalSeconds % 60;
        const totalMinutes = Math.floor(totalSeconds / 60);
        const minutes = totalMinutes % 60;
        const hours = Math.floor(totalMinutes / 60);
        const minuteText = String(minutes).padStart(2, '0');
        const secondText = String(seconds).padStart(2, '0');

        return hours > 0
            ? `${hours}:${minuteText}:${secondText}.${tenths}`
            : `${minuteText}:${secondText}.${tenths}`;
    };

    const formatMeasurement = (milliseconds) => Number.isSafeInteger(milliseconds)
        ? `${milliseconds} ms (${formatTime(milliseconds)})`
        : '—';

    const renderMediaDebug = () => {
        debugFields.mediaMode.textContent = mediaDebug.mediaMode;
        debugFields.atLiveEdge.textContent = String(mediaDebug.atLiveEdge);
        debugFields.transmissionRevision.textContent = mediaDebug.transmissionRevision ?? '—';
        debugFields.playbackRevision.textContent = mediaDebug.playbackRevision ?? '—';
        debugFields.playerReady.textContent = String(mediaDebug.playerReady);
        debugFields.playerState.textContent = mediaDebug.playerState === null
            ? '—'
            : `${mediaDebug.playerState} (${stateLabels.get(mediaDebug.playerState) ?? 'desconhecido'})`;
        debugFields.currentTime.textContent = formatMeasurement(mediaDebug.positionMs);
        debugFields.duration.textContent = formatMeasurement(mediaDebug.durationMs);
        debugFields.liveEdge.textContent = formatMeasurement(mediaDebug.liveEdgePositionMs);
        debugFields.behindLive.textContent = formatMeasurement(mediaDebug.behindLiveMs);
        debugFields.uiBranch.textContent = mediaDebug.uiBranch;
    };

    const describeComparison = (participant) => {
        if (participant.is_you && participant.playback.drift_ms === 0) {
            return 'referência';
        }

        if (typeof participant.playback.drift_ms !== 'number') {
            return 'comparação indisponível';
        }

        const driftSeconds = participant.playback.drift_ms / 1000;
        const sign = driftSeconds >= 0 ? '+' : '';
        return `${sign}${driftSeconds.toFixed(1)} s vs você`;
    };

    const describePlayback = (participant) => {
        const playback = participant.playback;
        if (playback === null) {
            return 'Sem dados do player';
        }

        if (!playback.fresh) {
            return 'Sem dados recentes do player';
        }

        const state = stateLabels.get(playback.state) ?? 'Estado indisponível';
        return `${state} · ${formatTime(playback.position_ms)} · ${describeComparison(participant)}`;
    };

    const render = (participants) => {
        const fragment = document.createDocumentFragment();

        participants.forEach((participant) => {
            const item = document.createElement('li');
            const name = document.createElement('strong');
            const playback = document.createElement('span');

            name.textContent = participant.is_you ? `${participant.name} (você)` : participant.name;
            playback.textContent = describePlayback(participant);
            item.append(name, playback);
            fragment.append(item);
        });

        telemetryList.replaceChildren(fragment);
    };

    document.addEventListener('semyra:presence-updated', (event) => {
        const participants = event.detail?.participants;
        if (Array.isArray(participants)) {
            render(participants);
        }
    });

    document.addEventListener('semyra:media-debug', (event) => {
        const detail = event.detail;
        if (detail?.source === 'player') {
            if (typeof detail.playerReady === 'boolean') {
                mediaDebug.playerReady = detail.playerReady;
            }
            const snapshot = detail.snapshot;
            if (snapshot !== null && typeof snapshot === 'object') {
                mediaDebug.playerState = snapshot.state;
                mediaDebug.positionMs = snapshot.positionMs;
                mediaDebug.durationMs = snapshot.durationMs;
            } else if (Number.isInteger(detail.playerState)) {
                mediaDebug.playerState = detail.playerState;
            }
        }
        if (detail?.source === 'playback') {
            const transmission = detail.transmission;
            mediaDebug.mediaMode = transmission?.mediaMode ?? 'unknown';
            mediaDebug.atLiveEdge = transmission?.playback.atLiveEdge ?? false;
            mediaDebug.transmissionRevision = transmission?.revision ?? null;
            mediaDebug.playbackRevision = transmission?.playback.revision ?? null;
            mediaDebug.liveEdgePositionMs = detail.liveEdgePositionMs;
            mediaDebug.behindLiveMs = detail.behindLiveMs;
            mediaDebug.uiBranch = detail.uiBranch ?? 'preparing';
        }
        renderMediaDebug();
    });

    renderMediaDebug();
})();
