'use strict';

((root, factory) => {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory;
        return;
    }
    factory({
        window: root,
        document: root.document,
        fetch: root.fetch.bind(root),
        noiseSuppression: root.SemyraNoiseSuppression,
    });
})(typeof window !== 'undefined' ? window : globalThis, ({window, document, fetch, loadSdk, noiseSuppression}) => {
    const shell = document.querySelector('[data-room-shell]');
    const panel = document.querySelector('[data-room-voice]');
    if (!shell || !panel) return null;

    const tokenUrl = shell.dataset.livekitVoiceTokenUrl ?? '';
    const sdkUrl = shell.dataset.livekitClientSrc ?? '';
    const csrfToken = shell.dataset.csrfToken ?? '';
    const rnnoiseWorkletUrl = shell.dataset.rnnoiseWorkletUrl ?? '';
    const microphoneButton = panel.querySelector('[data-voice-microphone]');
    const microphoneLabel = panel.querySelector('[data-voice-microphone-label]');
    const microphoneIcon = panel.querySelector('[data-voice-microphone-icon]');
    const listenButton = panel.querySelector('[data-voice-listen]');
    const listenLabel = panel.querySelector('[data-voice-listen-label]');
    const listenIcon = panel.querySelector('[data-voice-listen-icon]');
    const volumeInput = panel.querySelector('[data-voice-volume]');
    const connection = panel.querySelector('[data-voice-connection]');
    const status = panel.querySelector('[data-voice-status]');
    const participantsList = panel.querySelector('[data-voice-participants]');
    const audioMount = panel.querySelector('[data-voice-audio-mount]');
    const standardModeInput = panel.querySelector('[data-voice-noise-mode="standard"]');
    const enhancedModeInput = panel.querySelector('[data-voice-noise-mode="enhanced"]');
    const noiseHelp = panel.querySelector('[data-voice-noise-help]');
    const toolbarButton = shell.querySelector('[data-voice-toolbar-button]');
    const toolbarIcon = shell.querySelector('[data-voice-toolbar-icon]');
    const hudIndicator = shell.querySelector('[data-voice-button-indicator]');
    if (!tokenUrl || !sdkUrl || !csrfToken || !microphoneButton || !listenButton
        || !volumeInput || !connection || !status || !participantsList || !audioMount
        || !standardModeInput || !enhancedModeInput || !noiseHelp) return null;

    let sdkPromise = null;
    let room = null;
    let ownIdentity = null;
    let microphoneEnabled = false;
    let receivedMuted = false;
    let receivedVolume = 100;
    let connecting = null;
    let destroyed = false;
    let noiseMode = 'standard';
    let activeMicrophoneMode = null;
    let enhancedPipeline = null;
    let enhancedTrack = null;
    const audioElements = new Map();
    const speaking = new Set();

    const enhancedSupported = Boolean(
        rnnoiseWorkletUrl
        && noiseSuppression
        && typeof noiseSuppression.isSupported === 'function'
        && noiseSuppression.isSupported()
    );
    noiseMode = enhancedSupported ? 'enhanced' : 'standard';
    enhancedModeInput.disabled = !enhancedSupported;
    enhancedModeInput.checked = enhancedSupported;
    standardModeInput.checked = !enhancedSupported;
    if (!enhancedSupported) {
        noiseHelp.textContent = 'Aprimorado indisponível neste navegador. O processamento Padrão continua ativo.';
    }

    const randomId = () => {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    };
    // A fresh nonce per document avoids LiveKit identity collisions when a tab
    // is duplicated (sessionStorage can be cloned by the browser).
    const voiceInstanceId = randomId();

    const validSdk = (sdk) => sdk && typeof sdk.Room === 'function' && sdk.RoomEvent && sdk.Track;
    const ensureSdk = () => {
        if (validSdk(window.LivekitClient)) return Promise.resolve(window.LivekitClient);
        if (sdkPromise) return sdkPromise;
        if (typeof loadSdk === 'function') return Promise.resolve(loadSdk());
        sdkPromise = new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[src="${sdkUrl}"]`);
            const script = existing ?? document.createElement('script');
            script.addEventListener('load', () => validSdk(window.LivekitClient)
                ? resolve(window.LivekitClient) : reject(new Error('invalid_sdk')), {once: true});
            script.addEventListener('error', () => reject(new Error('sdk_failed')), {once: true});
            if (!existing) {
                script.src = sdkUrl;
                script.async = true;
                document.head.append(script);
            }
        });
        return sdkPromise;
    };

    const setConnection = (message, state = '') => {
        connection.textContent = message;
        connection.dataset.state = state;
    };
    const updateMicrophone = () => {
        microphoneButton.setAttribute('aria-pressed', String(microphoneEnabled));
        microphoneButton.dataset.state = microphoneEnabled ? 'active' : 'muted';
        microphoneLabel.textContent = microphoneEnabled ? 'Desativar microfone' : 'Ativar microfone';
        microphoneButton.setAttribute('aria-label', microphoneLabel.textContent);
        microphoneIcon?.setAttribute('href', microphoneEnabled ? '#room-icon-mic' : '#room-icon-mic-off');
        toolbarIcon?.setAttribute('href', microphoneEnabled ? '#room-icon-mic' : '#room-icon-mic-off');
        if (toolbarButton) {
            toolbarButton.dataset.microphoneState = microphoneEnabled ? 'active' : 'muted';
            toolbarButton.setAttribute('aria-label', microphoneEnabled
                ? 'Abrir chat de voz — microfone ativo'
                : 'Abrir chat de voz — microfone desligado');
        }
        if (hudIndicator) {
            hudIndicator.hidden = !microphoneEnabled;
            hudIndicator.dataset.state = microphoneEnabled ? 'active' : '';
        }
    };
    const updateListening = () => {
        listenButton.setAttribute('aria-pressed', String(receivedMuted));
        listenButton.dataset.state = receivedMuted ? 'muted' : 'listening';
        listenLabel.textContent = receivedMuted ? 'Ouvir participantes' : 'Silenciar participantes';
        listenButton.setAttribute('aria-label', listenLabel.textContent);
        listenIcon?.setAttribute('href', receivedMuted ? '#room-icon-volume-x' : '#room-icon-volume');
        volumeInput.style?.setProperty('--voice-volume-progress', `${receivedVolume}%`);
        for (const element of audioElements.values()) {
            element.muted = receivedMuted;
            element.volume = receivedVolume / 100;
        }
    };
    const remoteValues = () => {
        const values = room?.remoteParticipants;
        return values instanceof Map ? Array.from(values.values()) : Object.values(values ?? {});
    };
    const microphonePublication = (participant, sdk) => {
        const publications = participant?.audioTrackPublications ?? participant?.trackPublications;
        const values = publications instanceof Map ? publications.values() : Object.values(publications ?? {});
        return Array.from(values).find((publication) => {
            const source = publication?.source ?? publication?.track?.source;
            return source === sdk.Track.Source.Microphone;
        }) ?? null;
    };
    const renderParticipants = (sdk) => {
        participantsList.replaceChildren();
        const participants = room ? [room.localParticipant, ...remoteValues()] : [];
        participants.forEach((participant) => {
            if (!participant) return;
            const item = document.createElement('li');
            const avatar = document.createElement('span');
            const copy = document.createElement('span');
            const name = document.createElement('strong');
            const state = document.createElement('small');
            const publication = microphonePublication(participant, sdk);
            const isLocal = participant.identity === ownIdentity;
            const active = isLocal ? microphoneEnabled : Boolean(publication && publication.isMuted !== true);
            avatar.className = 'room-voice-avatar';
            avatar.textContent = (participant.name || 'P').trim().slice(0, 1).toUpperCase();
            name.textContent = `${participant.name || 'Participante'}${isLocal ? ' (voce)' : ''}`;
            state.textContent = active ? 'Microfone ativo' : 'Microfone desligado';
            copy.append(name, state);
            item.append(avatar, copy);
            item.classList.toggle('is-speaking', speaking.has(participant.identity));
            item.classList.toggle('is-muted', !active);
            item.classList.toggle('is-you', isLocal);
            participantsList.append(item);
        });
    };
    const publicationAllowed = (publication, sdk) => (publication?.source ?? publication?.track?.source)
        === sdk.Track.Source.Microphone;
    const subscribe = (publication, sdk) => {
        if (!publicationAllowed(publication, sdk)) return;
        if (publication.isSubscribed !== true && typeof publication.setSubscribed === 'function') {
            Promise.resolve(publication.setSubscribed(true)).catch(() => {});
        }
    };
    const inspectParticipant = (participant, sdk) => {
        const publications = participant?.trackPublications;
        const values = publications instanceof Map ? publications.values() : Object.values(publications ?? {});
        for (const publication of values) subscribe(publication, sdk);
    };
    const attachAudio = (track, publication, participant, sdk) => {
        if (!publicationAllowed(publication, sdk) || participant?.identity === ownIdentity) return;
        const sid = publication?.trackSid ?? track?.sid ?? participant.identity;
        if (audioElements.has(sid)) return;
        const element = track.attach();
        element.autoplay = true;
        element.controls = false;
        element.muted = receivedMuted;
        element.volume = receivedVolume / 100;
        element.dataset.voiceTrack = sid;
        audioElements.set(sid, element);
        audioMount.append(element);
    };
    const detachAudio = (track, publication) => {
        const sid = publication?.trackSid ?? track?.sid;
        const element = sid ? audioElements.get(sid) : null;
        try { track?.detach?.(element ?? undefined); } catch { }
        element?.remove();
        if (sid) audioElements.delete(sid);
    };

    const requestToken = async () => {
        const response = await fetch(tokenUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json'},
            body: new URLSearchParams({_token: csrfToken, voice_instance_id: voiceInstanceId}),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error ?? 'voice_token_failed');
        if (typeof payload.server_url !== 'string' || !payload.server_url.startsWith('wss://')
            || typeof payload.participant_token !== 'string' || payload.participant_token === ''
            || !/^smy_c_[a-f0-9]{32}$/.test(payload.participant_identity ?? '')) {
            throw new Error('invalid_voice_token');
        }
        return payload;
    };

    const stopEnhancedPipeline = async (participant, unpublish = true) => {
        const pipeline = enhancedPipeline;
        const track = enhancedTrack;
        enhancedPipeline = null;
        enhancedTrack = null;
        if (unpublish && track && typeof participant?.unpublishTrack === 'function') {
            try { await participant.unpublishTrack(track); } catch { }
        }
        if (pipeline) await pipeline.stop();
    };
    const disableMicrophone = async (participant, {disconnected = false} = {}) => {
        const mode = activeMicrophoneMode;
        activeMicrophoneMode = null;
        microphoneEnabled = false;
        updateMicrophone();
        if (mode === 'enhanced' || enhancedPipeline || enhancedTrack) {
            await stopEnhancedPipeline(participant, !disconnected);
        } else if (mode === 'standard' && !disconnected) {
            await participant?.setMicrophoneEnabled?.(false);
        }
    };
    const enableStandardMicrophone = async (participant) => {
        await participant.setMicrophoneEnabled(true, {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
            channelCount: 1,
        });
        activeMicrophoneMode = 'standard';
        microphoneEnabled = true;
    };
    const handleEnhancedRuntimeFailure = async () => {
        if (activeMicrophoneMode !== 'enhanced' || destroyed) return;
        noiseMode = 'standard';
        standardModeInput.checked = true;
        enhancedModeInput.checked = false;
        await disableMicrophone(room?.localParticipant);
        status.textContent = 'A redução Aprimorada foi interrompida. Ative novamente para usar o modo Padrão.';
        if (room) renderParticipants(await ensureSdk());
    };
    const enableEnhancedMicrophone = async (participant, sdk) => {
        const stream = await window.navigator.mediaDevices.getUserMedia({audio: {
            echoCancellation: true,
            noiseSuppression: false,
            autoGainControl: false,
            channelCount: {ideal: 1},
            sampleRate: {ideal: 48000},
        }, video: false});
        const inputTrack = stream.getAudioTracks()[0] ?? null;
        if (!inputTrack) throw new Error('microphone_track_missing');
        let pipeline = null;
        try {
            pipeline = await noiseSuppression.create({
                inputTrack,
                workletUrl: rnnoiseWorkletUrl,
                onError: () => void handleEnhancedRuntimeFailure(),
            });
            if (typeof participant.publishTrack !== 'function') throw new Error('publish_track_unavailable');
            await participant.publishTrack(pipeline.track, {
                source: sdk.Track.Source.Microphone,
                name: 'semyra-voice-rnnoise',
            });
            enhancedPipeline = pipeline;
            enhancedTrack = pipeline.track;
            activeMicrophoneMode = 'enhanced';
            microphoneEnabled = true;
        } catch (error) {
            if (pipeline) await pipeline.stop();
            throw error;
        }
    };
    const connect = async () => {
        if (destroyed || room) return room;
        if (connecting) return connecting;
        connecting = (async () => {
            setConnection('Conectando...', 'connecting');
            try {
                const [sdk, token] = await Promise.all([ensureSdk(), requestToken()]);
                if (destroyed) return null;
                ownIdentity = token.participant_identity;
                const nextRoom = new sdk.Room({adaptiveStream: true, disconnectOnPageLeave: true});
                nextRoom.on(sdk.RoomEvent.ParticipantConnected, (participant) => {
                    inspectParticipant(participant, sdk);
                    renderParticipants(sdk);
                });
                nextRoom.on(sdk.RoomEvent.ParticipantDisconnected, (participant) => {
                    speaking.delete(participant.identity);
                    renderParticipants(sdk);
                });
                nextRoom.on(sdk.RoomEvent.TrackPublished, (publication) => {
                    subscribe(publication, sdk);
                    renderParticipants(sdk);
                });
                nextRoom.on(sdk.RoomEvent.TrackSubscribed, (track, publication, participant) => {
                    attachAudio(track, publication, participant, sdk);
                    renderParticipants(sdk);
                });
                nextRoom.on(sdk.RoomEvent.TrackUnsubscribed, (track, publication) => {
                    detachAudio(track, publication);
                    renderParticipants(sdk);
                });
                nextRoom.on(sdk.RoomEvent.TrackMuted, () => renderParticipants(sdk));
                nextRoom.on(sdk.RoomEvent.TrackUnmuted, () => renderParticipants(sdk));
                nextRoom.on(sdk.RoomEvent.ActiveSpeakersChanged, (active) => {
                    speaking.clear();
                    active.forEach((participant) => speaking.add(participant.identity));
                    renderParticipants(sdk);
                });
                nextRoom.on(sdk.RoomEvent.Reconnecting, () => setConnection('Reconectando...', 'connecting'));
                nextRoom.on(sdk.RoomEvent.Reconnected, () => setConnection('Conectado', 'connected'));
                nextRoom.on(sdk.RoomEvent.Disconnected, () => {
                    void disableMicrophone(nextRoom.localParticipant, {disconnected: true});
                    room = null;
                    setConnection('Desconectado', 'error');
                    if (!destroyed) window.setTimeout(() => void connect(), 3000);
                });
                await nextRoom.connect(token.server_url, token.participant_token, {autoSubscribe: false});
                if (destroyed) {
                    await nextRoom.disconnect();
                    return null;
                }
                room = nextRoom;
                for (const participant of remoteValues()) inspectParticipant(participant, sdk);
                setConnection('Conectado', 'connected');
                status.textContent = 'O microfone inicia desligado.';
                renderParticipants(sdk);
                return nextRoom;
            } catch (error) {
                setConnection('Indisponivel', 'error');
                status.textContent = error?.message === 'join_required'
                    ? 'Entre novamente na sala para usar a voz.'
                    : 'Chat de voz indisponivel. Tentaremos reconectar.';
                if (!destroyed) window.setTimeout(() => void connect(), 5000);
                return null;
            } finally {
                connecting = null;
            }
        })();
        return connecting;
    };

    microphoneButton.addEventListener('click', async () => {
        microphoneButton.disabled = true;
        try {
            const currentRoom = room ?? await connect();
            if (!currentRoom) throw new Error('not_connected');
            if (!microphoneEnabled) {
                await currentRoom.startAudio?.();
                if (noiseMode === 'enhanced') {
                    try {
                        await enableEnhancedMicrophone(currentRoom.localParticipant, await ensureSdk());
                        status.textContent = 'Seu microfone está ativo com redução de ruído Aprimorada.';
                    } catch (enhancedError) {
                        noiseMode = 'standard';
                        standardModeInput.checked = true;
                        enhancedModeInput.checked = false;
                        await stopEnhancedPipeline(currentRoom.localParticipant);
                        await enableStandardMicrophone(currentRoom.localParticipant);
                        status.textContent = 'Aprimorado indisponível; seu microfone está ativo no modo Padrão.';
                    }
                } else {
                    await enableStandardMicrophone(currentRoom.localParticipant);
                    status.textContent = 'Seu microfone está ativo no modo Padrão.';
                }
            } else {
                await disableMicrophone(currentRoom.localParticipant);
                status.textContent = 'Seu microfone está desligado.';
            }
            updateMicrophone();
            renderParticipants(await ensureSdk());
        } catch (error) {
            microphoneEnabled = false;
            updateMicrophone();
            const name = error?.name ?? '';
            status.textContent = name === 'NotAllowedError'
                ? 'Permissao do microfone negada. Libere-a nas configuracoes do navegador.'
                : name === 'NotFoundError'
                    ? 'Nenhum microfone foi encontrado.'
                    : 'Nao foi possivel capturar o microfone. Tente novamente.';
        } finally {
            microphoneButton.disabled = false;
        }
    });
    const changeNoiseMode = async (event) => {
        const selected = event.currentTarget?.value;
        if (selected !== 'standard' && selected !== 'enhanced') return;
        if (selected === 'enhanced' && !enhancedSupported) {
            standardModeInput.checked = true;
            return;
        }
        if (microphoneEnabled) {
            microphoneButton.disabled = true;
            try {
                await disableMicrophone(room?.localParticipant);
                status.textContent = 'Modo alterado. Ative o microfone novamente quando quiser falar.';
                if (room) renderParticipants(await ensureSdk());
            } finally {
                microphoneButton.disabled = false;
            }
        }
        noiseMode = selected;
    };
    standardModeInput.addEventListener('change', changeNoiseMode);
    enhancedModeInput.addEventListener('change', changeNoiseMode);
    listenButton.addEventListener('click', async () => {
        receivedMuted = !receivedMuted;
        if (!receivedMuted) {
            try { await room?.startAudio?.(); } catch { status.textContent = 'O navegador bloqueou o audio. Tente novamente.'; }
        }
        updateListening();
    });
    volumeInput.addEventListener('input', async () => {
        receivedVolume = Math.max(0, Math.min(100, Number(volumeInput.value) || 0));
        receivedMuted = receivedVolume === 0;
        if (!receivedMuted) {
            try { await room?.startAudio?.(); } catch { }
        }
        updateListening();
    });
    window.addEventListener('pagehide', () => {
        destroyed = true;
        for (const element of audioElements.values()) element.remove();
        audioElements.clear();
        void disableMicrophone(room?.localParticipant);
        void room?.disconnect?.();
        room = null;
    }, {once: true});

    updateMicrophone();
    updateListening();
    void connect();

    return {connect, state: () => ({
        room, microphoneEnabled, receivedMuted, receivedVolume, noiseMode, activeMicrophoneMode,
        hasEnhancedPipeline: Boolean(enhancedPipeline),
    })};
});
