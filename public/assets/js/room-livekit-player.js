'use strict';

((root, factory) => {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory;
        return;
    }
    factory({window: root, document: root.document, fetch: root.fetch.bind(root)});
})(typeof window !== 'undefined' ? window : globalThis, ({window, document, fetch, loadSdk}) => {
    const shell = document.querySelector('[data-room-shell]');
    const mount = document.querySelector('[data-livekit-player-mount]');
    const statusElement = document.getElementById('livekit-player-status');
    const media = window.SemyraMedia;
    if (!shell || !mount || !statusElement || !media) {
        return null;
    }

    const tokenUrl = shell.dataset.livekitViewerTokenUrl ?? '';
    const sdkUrl = shell.dataset.livekitClientSrc ?? '';
    const csrfToken = shell.dataset.csrfToken ?? '';
    const publisherPattern = /^smy_i_[a-f0-9]{32}$/;
    const instancePattern = /^[a-f0-9]{32}$/;
    let sdkPromise = null;
    let generation = 0;
    let activeTransmission = null;
    let room = null;
    let expectedPublisherIdentity = null;
    let videoTrack = null;
    let videoElement = null;
    let audioTrack = null;
    let audioElement = null;
    let volumeStorage = null;
    try {
        volumeStorage = window.localStorage;
    } catch {
        // Storage may be unavailable in privacy-restricted contexts.
    }
    let selectedVolume = media.readStoredPlayerVolume(volumeStorage);
    let restoreVolume = selectedVolume > 0 ? selectedVolume : media.DEFAULT_PLAYER_VOLUME;
    let locallyMuted = true;
    let audioSessionKey = null;
    const debugEnabled = document.querySelector('[data-room-telemetry]') !== null;
    const trackReplacementStartedAt = {video: null, audio: null};
    const trackTimings = [];

    const debugNow = () => Number(window.performance?.now?.() ?? Date.now());

    const recordTrackTiming = (role) => {
        const startedAt = trackReplacementStartedAt[role];
        if (!debugEnabled || startedAt === null) return;
        trackTimings.push(Object.freeze({
            phase: `new_livekit_${role}_track_subscribed`,
            elapsedMs: Math.max(0, Math.round(debugNow() - startedAt)),
        }));
        trackReplacementStartedAt[role] = null;
    };

    const finiteNumber = (value) => Number.isFinite(Number(value)) ? Number(value) : null;

    const videoStats = async () => {
        if (!videoTrack || typeof videoTrack.getRTCStatsReport !== 'function') {
            return null;
        }
        const report = await videoTrack.getRTCStatsReport();
        const entries = [];
        if (report && typeof report.forEach === 'function') {
            report.forEach((entry) => entries.push(entry));
        } else if (report && typeof report[Symbol.iterator] === 'function') {
            entries.push(...report);
        }
        const inbound = entries.find((entry) => entry?.type === 'inbound-rtp'
            && (entry.kind === 'video' || entry.mediaType === 'video'));
        if (!inbound) {
            return null;
        }
        const codec = entries.find((entry) => entry?.type === 'codec' && entry.id === inbound.codecId);
        const mimeType = typeof codec?.mimeType === 'string' && /^video\/[a-z0-9.+-]+$/i.test(codec.mimeType)
            ? codec.mimeType
            : null;
        const fmtp = typeof codec?.sdpFmtpLine === 'string'
            && /^[a-z0-9=;,_ .-]{0,512}$/i.test(codec.sdpFmtpLine)
            ? codec.sdpFmtpLine
            : null;
        return Object.freeze({
            framesReceived: finiteNumber(inbound.framesReceived),
            framesDecoded: finiteNumber(inbound.framesDecoded),
            framesDropped: finiteNumber(inbound.framesDropped),
            framesPerSecond: finiteNumber(inbound.framesPerSecond),
            keyFramesDecoded: finiteNumber(inbound.keyFramesDecoded),
            packetsReceived: finiteNumber(inbound.packetsReceived),
            packetsLost: finiteNumber(inbound.packetsLost),
            jitter: finiteNumber(inbound.jitter),
            bytesReceived: finiteNumber(inbound.bytesReceived),
            codec: Object.freeze({mimeType, fmtp}),
        });
    };

    if (debugEnabled) {
        window.SemyraLiveKitDebug = Object.freeze({
            getVideoStats: videoStats,
            getTrackTimings: () => trackTimings.slice(),
        });
    }

    const isLiveKitTransmission = (transmission) => transmission?.source === 'iptv'
        && ['live', 'vod'].includes(transmission?.mediaMode)
        && typeof transmission?.instanceId === 'string'
        && instancePattern.test(transmission.instanceId)
        && Number.isSafeInteger(transmission?.revision)
        && transmission.revision > 0;

    const transmissionAudioSessionKey = (transmission) => isLiveKitTransmission(transmission)
        ? `${transmission.instanceId}:${transmission.revision}`
        : null;

    const updateStatus = (message, isError = false) => {
        statusElement.hidden = message === '';
        statusElement.textContent = message;
        statusElement.classList.toggle('is-error', isError);
    };

    const emitAudioState = () => {
        if (!isLiveKitTransmission(activeTransmission)) {
            return;
        }
        document.dispatchEvent(new CustomEvent('semyra:player-audio-state', {
            detail: {muted: locallyMuted, volume: selectedVolume},
        }));
    };

    const removeAttached = (kind) => {
        const track = kind === 'video' ? videoTrack : audioTrack;
        const element = kind === 'video' ? videoElement : audioElement;
        if (track && typeof track.detach === 'function') {
            try {
                track.detach(element ?? undefined);
            } catch {
                // The DOM element is removed below even if SDK cleanup fails.
            }
        }
        element?.remove();
        if (kind === 'video') {
            videoTrack = null;
            videoElement = null;
        } else {
            audioTrack = null;
            audioElement = null;
        }
    };

    const clearTracks = () => {
        removeAttached('video');
        removeAttached('audio');
    };

    const disconnectRoom = async () => {
        const previousRoom = room;
        room = null;
        expectedPublisherIdentity = null;
        clearTracks();
        if (previousRoom && typeof previousRoom.disconnect === 'function') {
            try {
                await previousRoom.disconnect();
            } catch {
                // Disconnect is best-effort during source/revision changes.
            }
        }
    };

    const deactivate = async () => {
        ++generation;
        activeTransmission = null;
        audioSessionKey = null;
        locallyMuted = true;
        await disconnectRoom();
        mount.replaceChildren();
        mount.hidden = true;
        updateStatus('');
    };

    const validSdk = (sdk) => sdk
        && typeof sdk.Room === 'function'
        && sdk.RoomEvent
        && sdk.Track;

    const ensureSdk = () => {
        if (validSdk(window.LivekitClient)) {
            return Promise.resolve(window.LivekitClient);
        }
        if (sdkPromise !== null) {
            return sdkPromise;
        }
        if (typeof loadSdk === 'function') {
            sdkPromise = Promise.resolve(loadSdk()).then((sdk) => {
                if (!validSdk(sdk)) {
                    throw new Error('Invalid LiveKit SDK.');
                }
                return sdk;
            });
            return sdkPromise;
        }
        sdkPromise = new Promise((resolve, reject) => {
            if (!sdkUrl) {
                reject(new Error('Missing LiveKit SDK URL.'));
                return;
            }
            const existing = document.querySelector(`script[src="${sdkUrl}"]`);
            const script = existing ?? document.createElement('script');
            const complete = () => validSdk(window.LivekitClient)
                ? resolve(window.LivekitClient)
                : reject(new Error('Invalid LiveKit SDK.'));
            script.addEventListener('load', complete, {once: true});
            script.addEventListener('error', reject, {once: true});
            if (!existing) {
                script.src = sdkUrl;
                script.async = true;
                document.head.append(script);
            }
        });
        return sdkPromise;
    };

    const publicationKind = (publication, sdk) => publication?.kind
        ?? publication?.track?.kind
        ?? (publication?.isVideo ? sdk.Track.Kind.Video : null)
        ?? (publication?.isAudio ? sdk.Track.Kind.Audio : null);

    const subscribePublication = (publication, participant, sdk) => {
        if (participant?.identity !== expectedPublisherIdentity) {
            return;
        }
        const kind = publicationKind(publication, sdk);
        if (![sdk.Track.Kind.Video, sdk.Track.Kind.Audio].includes(kind)) {
            return;
        }
        if (publication.isSubscribed !== true && typeof publication.setSubscribed === 'function') {
            Promise.resolve(publication.setSubscribed(true)).catch(() => {
                updateStatus('Aguardando transmissão…');
            });
        }
    };

    const inspectParticipant = (participant, sdk) => {
        if (participant?.identity !== expectedPublisherIdentity) {
            return;
        }
        const publications = participant.trackPublications;
        const values = publications instanceof Map
            ? publications.values()
            : Object.values(publications ?? {});
        for (const publication of values) {
            subscribePublication(publication, participant, sdk);
        }
    };

    const preserveAudiblePlayback = async (element, capturedGeneration) => {
        if (locallyMuted) return;
        try {
            await room?.startAudio?.();
            if (capturedGeneration !== generation || element !== audioElement) return;
            if (typeof element.play === 'function') await element.play();
        } catch {
            if (capturedGeneration !== generation || element !== audioElement) return;
            locallyMuted = true;
            element.muted = true;
            updateStatus('O navegador bloqueou o áudio. Ative o som novamente.', true);
            emitAudioState();
        }
    };

    const attachTrack = (track, publication, participant, sdk, capturedGeneration) => {
        if (capturedGeneration !== generation
            || participant?.identity !== expectedPublisherIdentity) {
            try {
                track?.detach?.();
            } catch {
                // Unexpected participant media is ignored and never rendered.
            }
            if (typeof publication?.setSubscribed === 'function') {
                Promise.resolve(publication.setSubscribed(false)).catch(() => {});
            }
            return;
        }
        const kind = track?.kind ?? publicationKind(publication, sdk);
        if (kind !== sdk.Track.Kind.Video && kind !== sdk.Track.Kind.Audio) {
            return;
        }
        const role = kind === sdk.Track.Kind.Video ? 'video' : 'audio';
        removeAttached(role);
        const element = track.attach();
        if (role === 'video') {
            element.autoplay = true;
            element.playsInline = true;
            element.controls = false;
            element.classList.add('room-livekit-video');
            videoTrack = track;
            videoElement = element;
        } else {
            element.autoplay = true;
            element.controls = false;
            element.volume = Math.min(1, Math.max(0, selectedVolume / 100));
            element.muted = locallyMuted;
            element.classList.add('room-livekit-audio');
            audioTrack = track;
            audioElement = element;
            emitAudioState();
            if (!locallyMuted) void preserveAudiblePlayback(element, capturedGeneration);
        }
        recordTrackTiming(role);
        mount.append(element);
        updateStatus(role === 'video'
            ? (activeTransmission?.mediaMode === 'live' ? 'AO VIVO' : 'Transmissão conectada.')
            : (locallyMuted ? 'Áudio conectado. Ative o som quando quiser.' : 'Áudio conectado.'));
    };

    const registerRoomEvents = (nextRoom, sdk, capturedGeneration) => {
        const on = (event, listener) => nextRoom.on(event, listener);
        on(sdk.RoomEvent.ParticipantConnected, (participant) => inspectParticipant(participant, sdk));
        on(sdk.RoomEvent.TrackPublished, (publication, participant) => {
            subscribePublication(publication, participant, sdk);
        });
        on(sdk.RoomEvent.TrackSubscribed, (track, publication, participant) => {
            attachTrack(track, publication, participant, sdk, capturedGeneration);
        });
        on(sdk.RoomEvent.TrackUnsubscribed, (track) => {
            if (track === videoTrack) removeAttached('video');
            if (track === audioTrack) removeAttached('audio');
            if (capturedGeneration === generation) updateStatus('Aguardando transmissão…');
        });
        on(sdk.RoomEvent.TrackUnpublished, (publication, participant) => {
            if (participant?.identity !== expectedPublisherIdentity) return;
            const kind = publicationKind(publication, sdk);
            if (kind === sdk.Track.Kind.Video) {
                if (debugEnabled) trackReplacementStartedAt.video = debugNow();
                removeAttached('video');
            }
            if (kind === sdk.Track.Kind.Audio) {
                if (debugEnabled) trackReplacementStartedAt.audio = debugNow();
                removeAttached('audio');
            }
            if (capturedGeneration === generation) updateStatus('Aguardando transmissão…');
        });
        on(sdk.RoomEvent.Reconnecting, () => updateStatus('Reconectando…'));
        on(sdk.RoomEvent.Reconnected, () => updateStatus('Transmissão reconectada.'));
        on(sdk.RoomEvent.Disconnected, () => {
            if (capturedGeneration === generation) {
                clearTracks();
                updateStatus('Transmissão desconectada.', true);
            }
        });
        on(sdk.RoomEvent.AudioPlaybackStatusChanged, emitAudioState);
    };

    const requestToken = async (instanceId, revision) => {
        const body = new URLSearchParams({
            _token: csrfToken,
            transmission_instance_id: instanceId,
            transmission_revision: String(revision),
        });
        const response = await fetch(tokenUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            credentials: 'same-origin',
            body,
        });
        let payload = {};
        try {
            payload = await response.json();
        } catch {
            // The sanitized status mapping below handles malformed responses.
        }
        return {response, payload};
    };

    const applyTransmission = async (transmission) => {
        if (!isLiveKitTransmission(transmission)) {
            await deactivate();
            return;
        }
        if (activeTransmission?.instanceId === transmission.instanceId
            && activeTransmission?.revision === transmission.revision
            && room !== null) {
            return;
        }

        const nextAudioSessionKey = transmissionAudioSessionKey(transmission);
        if (audioSessionKey !== nextAudioSessionKey) {
            audioSessionKey = nextAudioSessionKey;
            locallyMuted = true;
        }

        const capturedGeneration = ++generation;
        activeTransmission = transmission;
        await disconnectRoom();
        if (capturedGeneration !== generation) return;
        mount.hidden = false;
        updateStatus('Conectando à transmissão…');

        try {
            const sdk = await ensureSdk();
            if (capturedGeneration !== generation) return;
            const {response, payload} = await requestToken(
                transmission.instanceId,
                transmission.revision,
            );
            if (capturedGeneration !== generation) return;
            if (response.status === 409 && payload?.error === 'transmission_changed') {
                updateStatus('Atualizando transmissão…');
                document.dispatchEvent(new CustomEvent('semyra:presence-refresh-request'));
                return;
            }
            if (response.status !== 201) {
                const messages = {
                    403: 'Entre novamente na sala para assistir.',
                    419: 'A sessão expirou. Recarregue a página.',
                    503: 'Transmissão ao vivo indisponível agora.',
                };
                updateStatus(messages[response.status] ?? 'Não foi possível conectar à transmissão.', true);
                return;
            }
            if (typeof payload.server_url !== 'string'
                || !payload.server_url.startsWith('wss://')
                || typeof payload.participant_token !== 'string'
                || payload.participant_token === ''
                || payload.transmission_instance_id !== transmission.instanceId
                || payload.transmission_revision !== transmission.revision
                || typeof payload.publisher_identity !== 'string'
                || !publisherPattern.test(payload.publisher_identity)) {
                updateStatus('Resposta de transmissão inválida.', true);
                return;
            }

            expectedPublisherIdentity = payload.publisher_identity;
            const nextRoom = new sdk.Room({adaptiveStream: true, disconnectOnPageLeave: true});
            registerRoomEvents(nextRoom, sdk, capturedGeneration);
            await nextRoom.connect(payload.server_url, payload.participant_token, {autoSubscribe: false});
            if (capturedGeneration !== generation) {
                await nextRoom.disconnect();
                return;
            }
            room = nextRoom;
            const participants = nextRoom.remoteParticipants;
            const values = participants instanceof Map ? participants.values() : Object.values(participants ?? {});
            for (const participant of values) inspectParticipant(participant, sdk);
            updateStatus('Aguardando transmissão…');
        } catch {
            if (capturedGeneration === generation) {
                updateStatus('Não foi possível conectar à transmissão ao vivo.', true);
                await disconnectRoom();
            }
        }
    };

    document.addEventListener('semyra:transmission-updated', (event) => {
        void applyTransmission(event.detail?.transmission ?? null);
    });
    document.addEventListener('semyra:player-mute-toggle', async () => {
        if (!isLiveKitTransmission(activeTransmission)) return;
        if (locallyMuted) {
            try {
                await room?.startAudio?.();
                selectedVolume = media.playerUnmuteVolume(selectedVolume, restoreVolume);
                restoreVolume = selectedVolume;
                media.writeStoredPlayerVolume(volumeStorage, selectedVolume);
                if (audioElement) {
                    audioElement.volume = selectedVolume / 100;
                    audioElement.muted = false;
                }
                locallyMuted = false;
            } catch {
                locallyMuted = true;
                if (audioElement) audioElement.muted = true;
                updateStatus('O navegador bloqueou o áudio. Tente ativar o som novamente.', true);
            }
        } else {
            locallyMuted = true;
            if (audioElement) audioElement.muted = true;
        }
        emitAudioState();
    });
    document.addEventListener('semyra:player-volume-change', async (event) => {
        if (!isLiveKitTransmission(activeTransmission)) return;
        const selection = media.playerVolumeSelection(event.detail?.volume, restoreVolume);
        selectedVolume = selection.volume;
        restoreVolume = selection.restoreVolume;
        locallyMuted = selection.muted;
        media.writeStoredPlayerVolume(volumeStorage, selectedVolume);
        if (!locallyMuted) {
            try {
                await room?.startAudio?.();
            } catch {
                locallyMuted = true;
                updateStatus('O navegador bloqueou o áudio. Tente ativar o som novamente.', true);
            }
        }
        if (audioElement) {
            audioElement.volume = selectedVolume / 100;
            audioElement.muted = locallyMuted;
        }
        emitAudioState();
    });

    return {applyTransmission, deactivate, state: () => ({generation, activeTransmission, room})};
});
