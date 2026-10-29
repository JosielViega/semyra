'use strict';

((root, factory) => {
    if (typeof module === 'object' && module.exports) { module.exports = factory; return; }
    factory({window: root, document: root.document, navigator: root.navigator, fetch: root.fetch.bind(root)});
})(typeof window !== 'undefined' ? window : globalThis, ({window, document, navigator, fetch}) => {
    const shell = document.querySelector('[data-room-shell]');
    const panel = document.querySelector('[data-screen-source-panel]');
    const startButton = document.querySelector('[data-screen-share-start]');
    const targetList = document.querySelector('[data-screen-targets]');
    const quality = document.querySelector('[data-screen-share-quality]');
    const audio = document.querySelector('[data-screen-share-audio]');
    const audioLabel = document.querySelector('.room-screen-audio-label');
    const status = document.querySelector('[data-screen-share-status]');
    const previewFrame = document.querySelector('[data-screen-preview-frame]');
    const previewImage = document.querySelector('[data-screen-preview-image]');
    const previewPlaceholder = document.querySelector('[data-screen-preview-placeholder]');
    const previewMeta = document.querySelector('[data-screen-preview-meta]');
    if (!shell || !panel || !status) return null;

    const startUrl = shell.dataset.screenTransmissionUrl ?? '';
    const heartbeatUrl = shell.dataset.screenHeartbeatUrl ?? '';
    const stopUrl = shell.dataset.screenStopUrl ?? '';
    const hostSessionUrl = shell.dataset.desktopHostSessionUrl ?? '';
    const csrfToken = shell.dataset.csrfToken ?? '';
    const roomCode = /^\/room\/([A-Z0-9]{8})$/.exec(window.location.pathname)?.[1] ?? '';
    let capabilities = [];
    let targets = [];
    let phase = 'idle';
    let active = null;
    let heartbeatTimer = null;
    let authorizationRenewalTimer = null;
    let authorizationExpiresAt = 0;
    let authorizationRetryCount = 0;
    let generation = 0;
    let previewGeneration = 0;
    const startLabel = startButton?.textContent || 'Compartilhar';

    const setStatus = (message, error = false) => {
        status.textContent = message;
        status.classList.toggle('is-error', error);
    };
    const setPhase = (next) => {
        phase = next;
        const busy = ['authorizing', 'preparing', 'connecting', 'stopping'].includes(next);
        const selected = targetList?.querySelector('input:checked');
        if (startButton) {
            startButton.disabled = !['idle', 'error'].includes(next) || !selected || selected.disabled === true
                || !capabilities.includes('screen.publish');
            startButton.classList.toggle('is-loading', busy);
            startButton.setAttribute?.('aria-busy', busy ? 'true' : 'false');
            startButton.textContent = busy ? 'Preparando…' : startLabel;
        }
        panel.dataset.phase = next;
    };
    const post = async (url, values, keepalive = false) => {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', keepalive,
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: new URLSearchParams({_token: csrfToken, ...values})});
        let payload = {};
        try { payload = await response.json(); } catch { /* Sanitized below. */ }
        return {response, payload};
    };
    const requestNative = (action, detail = {}) => new Promise((resolve, reject) => {
        const timer = window.setTimeout(() => {
            window.removeEventListener('semyra:screen-result', listener);
            reject(new Error('desktop_bridge_timeout'));
        }, 30000);
        const listener = (event) => {
            if (event.detail?.action !== action) return;
            window.clearTimeout(timer);
            window.removeEventListener('semyra:screen-result', listener);
            event.detail.ok ? resolve(event.detail) : reject(new Error(event.detail.error || 'screen_command_failed'));
        };
        window.addEventListener('semyra:screen-result', listener);
        window.dispatchEvent(new CustomEvent('semyra:screen-request', {detail: {action, ...detail}}));
    });
    const authorizeNative = (payload) => new Promise((resolve, reject) => {
        const timer = window.setTimeout(() => {
            window.removeEventListener('semyra:host-authorized', listener);
            reject(new Error('host_authorization_timeout'));
        }, 10000);
        const listener = (event) => {
            if (event.detail?.permission !== 'screen.publish'
                || event.detail?.transmissionInstanceId !== payload.transmission.instance_id
                || event.detail?.transmissionRevision !== payload.transmission.revision) return;
            window.clearTimeout(timer);
            window.removeEventListener('semyra:host-authorized', listener);
            resolve();
        };
        window.addEventListener('semyra:host-authorized', listener);
        window.dispatchEvent(new CustomEvent('semyra:host-authorization-request', {detail: {
            hostSessionToken: payload.host_session_token, roomCode,
            transmissionInstanceId: payload.transmission.instance_id,
            transmissionRevision: payload.transmission.revision,
            permission: payload.permission, expiresAt: payload.expires_at,
        }}));
    });

    function stopAuthorizationRenewal() {
        if (authorizationRenewalTimer !== null) window.clearTimeout(authorizationRenewalTimer);
        authorizationRenewalTimer = null;
        authorizationExpiresAt = 0;
        authorizationRetryCount = 0;
    }
    function renewalDelay(expiresAt) {
        const remaining = Date.parse(expiresAt) - Date.now();
        if (!Number.isFinite(remaining) || remaining <= 0) return null;
        const lead = Math.min(60000, Math.max(5000, Math.floor(remaining / 4)));
        return Math.max(1000, remaining - lead);
    }
    function scheduleAuthorizationRenewal(expiresAt) {
        if (authorizationRenewalTimer !== null) window.clearTimeout(authorizationRenewalTimer);
        authorizationRenewalTimer = null;
        authorizationExpiresAt = Date.parse(expiresAt);
        authorizationRetryCount = 0;
        const delay = renewalDelay(expiresAt);
        if (!hostSessionUrl || delay === null) return;
        const expectedGeneration = generation;
        authorizationRenewalTimer = window.setTimeout(() => {
            authorizationRenewalTimer = null;
            void renewAuthorization(expectedGeneration);
        }, delay);
    }
    async function renewAuthorization(expectedGeneration) {
        const context = active;
        if (!context || expectedGeneration !== generation || !hostSessionUrl) return;
        try {
            const {response, payload} = await post(hostSessionUrl, {
                transmission_instance_id: context.instanceId,
                transmission_revision: String(context.revision),
            });
            if (expectedGeneration !== generation || active !== context) return;
            if (response.status !== 201 || payload?.permission !== 'screen.publish'
                || payload?.transmission_instance_id !== context.instanceId
                || payload?.transmission_revision !== context.revision
                || typeof payload?.host_session_token !== 'string'
                || typeof payload?.expires_at !== 'string') {
                throw new Error(payload?.error || 'host_authorization_renewal_failed');
            }
            await authorizeNative({
                host_session_token: payload.host_session_token,
                expires_at: payload.expires_at,
                permission: payload.permission,
                transmission: {instance_id: context.instanceId, revision: context.revision},
            });
            if (expectedGeneration !== generation || active !== context) return;
            scheduleAuthorizationRenewal(payload.expires_at);
        } catch {
            if (expectedGeneration !== generation || active !== context) return;
            authorizationRetryCount += 1;
            const delay = [5000, 15000, 30000][Math.min(authorizationRetryCount - 1, 2)];
            const remaining = authorizationExpiresAt - Date.now();
            if (authorizationRetryCount <= 3 && remaining > delay + 1000) {
                authorizationRenewalTimer = window.setTimeout(() => {
                    authorizationRenewalTimer = null;
                    void renewAuthorization(expectedGeneration);
                }, delay);
            }
        }
    }

    const selectedTarget = () => {
        const id = targetList?.querySelector('input:checked')?.value;
        return targets.find(target => target.id === id) ?? null;
    };
    const setPreview = (state, target = null, dataUrl = null) => {
        if (!previewFrame || !previewImage || !previewPlaceholder) return;
        previewFrame.dataset.state = state;
        previewMeta.textContent = target ? `${target.name} · ${target.width} × ${target.height}` : '';
        previewImage.hidden = state !== 'available';
        previewImage.src = state === 'available' && dataUrl ? dataUrl : '';
        previewPlaceholder.hidden = state === 'available';
        previewPlaceholder.textContent = state === 'loading' ? 'Gerando pré-visualização…'
            : state === 'unavailable' ? 'Pré-visualização indisponível'
                : state === 'removed' ? 'O item selecionado não está mais disponível.'
                    : 'Selecione um monitor ou uma janela.';
    };
    async function loadPreview(target) {
        const currentGeneration = ++previewGeneration;
        if (!capabilities.includes('screen.publish') || !target || target.available === false)
            return setPreview(target ? 'removed' : 'empty', target);
        setPreview('loading', target);
        try {
            const result = await requestNative('preview', {targetId: target.id});
            if (currentGeneration !== previewGeneration || selectedTarget()?.id !== target.id) return;
            if (result.state !== 'available' || typeof result.dataUrl !== 'string') throw new Error('screen_preview_unavailable');
            setPreview('available', target, result.dataUrl);
        } catch {
            if (currentGeneration === previewGeneration && selectedTarget()?.id === target.id)
                setPreview('unavailable', target);
        }
    }
    function cancelPreview(clear = true) {
        previewGeneration += 1;
        if (clear) setPreview('empty');
        if (capabilities.includes('screen.publish'))
            window.dispatchEvent(new CustomEvent('semyra:screen-request', {detail: {action: 'preview-cancel'}}));
    }
    const syncAudioCapability = () => {
        if (!audio) return;
        const available = capabilities.includes('screen.audio');
        audio.disabled = !available;
        if (!available) audio.checked = false;
        if (audioLabel) audioLabel.textContent = available
            ? 'Áudio do sistema'
            : 'Áudio do sistema (indisponível)';
        audio.closest?.('.room-screen-audio-option')?.setAttribute?.('title', available
            ? 'Inclui o áudio reproduzido pelo dispositivo padrão do Windows.'
            : 'Áudio do sistema indisponível neste Desktop.');
    };

    function renderTargets() {
        if (!targetList) return;
        targetList.replaceChildren();
        for (const [type, heading] of [['monitor', 'Monitores'], ['window', 'Janelas']]) {
            const items = targets.filter(target => target.type === type);
            if (items.length === 0) continue;
            const section = document.createElement('section');
            section.className = 'room-screen-target-section';
            const title = document.createElement('h4'); title.textContent = heading;
            const grid = document.createElement('div'); grid.className = 'room-screen-target-grid';
            for (const target of items) {
                const label = document.createElement('label'); label.className = 'room-screen-target';
                const input = document.createElement('input');
                input.type = 'radio'; input.name = 'screen-target'; input.value = target.id;
                input.disabled = target.available === false;
                input.addEventListener('change', () => { setPhase(phase); void loadPreview(target); });
                const icon = document.createElement('span'); icon.className = 'room-screen-target-icon';
                icon.setAttribute?.('aria-hidden', 'true'); icon.textContent = type === 'monitor' ? '▣' : '▢';
                const copy = document.createElement('span'); copy.className = 'room-screen-target-copy';
                const fullName = target.name || (type === 'monitor' ? 'Monitor' : 'Janela');
                label.title = fullName;
                label.setAttribute?.('aria-label', `${fullName}, ${target.width} por ${target.height}`);
                const name = document.createElement('strong'); name.textContent = fullName; name.title = fullName;
                const meta = document.createElement('small'); meta.textContent = `${target.width} × ${target.height}`;
                copy.append(name, meta); label.append(input, icon, copy); grid.append(label);
            }
            section.append(title, grid); targetList.append(section);
        }
        if (targets.length === 0) { setStatus('Nenhuma tela ou janela disponível.', true); setPreview('empty'); }
        setPhase(phase);
    }

    async function loadTargets() {
        if (!capabilities.includes('screen.publish')) {
            targets = []; renderTargets();
            setStatus('Abra esta sala no Semyra Desktop para compartilhar a tela.');
            return;
        }
        setStatus('Carregando telas e janelas…');
        try {
            const result = await requestNative('targets');
            targets = result.targets ?? [];
            renderTargets();
            setStatus(targets.length ? 'Selecione o que deseja compartilhar.' : 'Nenhuma tela ou janela disponível.', targets.length === 0);
        } catch { targets = []; renderTargets(); setStatus('Não foi possível listar telas e janelas.', true); }
    }

    const fencedValues = () => active ? {transmission_instance_id: active.instanceId,
        transmission_revision: String(active.revision)} : null;
    function stopHeartbeat() { if (heartbeatTimer !== null) window.clearTimeout(heartbeatTimer); heartbeatTimer = null; }
    function scheduleHeartbeat() {
        stopHeartbeat();
        const expectedGeneration = generation;
        heartbeatTimer = window.setTimeout(async () => {
            const values = fencedValues();
            if (expectedGeneration !== generation || !values) return;
            try {
                const {response} = await post(heartbeatUrl, values);
                if (expectedGeneration !== generation) return;
                if (!response.ok) return void cleanup(false);
            } catch { }
            if (expectedGeneration === generation && active) scheduleHeartbeat();
        }, 1500);
    }
    async function cleanup(notifyServer = true) {
        const values = fencedValues();
        active = null; stopHeartbeat(); stopAuthorizationRenewal(); generation += 1;
        try { await requestNative('stop'); } catch { /* Best effort native cleanup. */ }
        window.dispatchEvent(new CustomEvent('semyra:host-clear-request'));
        if (notifyServer && values && stopUrl) { try { await post(stopUrl, values, true); } catch { } }
        setPhase('idle');
    }

    async function start() {
        if (!startButton || !['idle', 'error'].includes(phase)) return;
        const targetId = targetList?.querySelector('input:checked')?.value;
        if (!targetId) return setStatus('Selecione uma tela ou janela.', true);
        const currentGeneration = ++generation;
        setPhase('authorizing'); setStatus('Autorizando compartilhamento…');
        try {
            const {response, payload} = await post(startUrl, {});
            if (response.status !== 201 || payload?.permission !== 'screen.publish'
                || typeof payload?.host_session_token !== 'string'
                || payload?.transmission?.source !== 'screen') throw new Error(payload?.error || 'start_failed');
            active = {instanceId: payload.transmission.instance_id, revision: payload.transmission.revision,
                startedAt: Date.now()};
            scheduleHeartbeat();
            await authorizeNative(payload);
            if (currentGeneration !== generation) return;
            scheduleAuthorizationRenewal(payload.expires_at);
            setPhase('preparing'); setStatus('Preparando compartilhamento…');
            const result = await requestNative('start', {targetId, quality: quality?.value ?? 'standard',
                audio: audio?.checked === true && capabilities.includes('screen.audio')});
            if (result.state !== 'streaming') throw new Error(result.errorCode || 'screen_publish_failed');
            setPhase('publishing');
            setStatus(result.audio ? `Compartilhando com áudio · ${result.encoder}.` : `Compartilhando sem áudio · ${result.encoder}.`);
            document.dispatchEvent(new CustomEvent('semyra:presence-refresh-request'));
        } catch (error) {
            await cleanup(active !== null);
            const messages = {
                screen_audio_failed: 'Não foi possível iniciar o áudio do sistema.',
                screen_audio_unavailable: 'O áudio do sistema não está disponível neste Desktop.',
                screen_capture_failed: 'Não foi possível capturar a tela selecionada.',
                screen_encoder_failed: 'Não foi possível iniciar o codificador de vídeo.',
                screen_whip_failed: 'Não foi possível conectar a transmissão.',
                screen_livekit_status_failed: 'Não foi possível confirmar a transmissão no LiveKit.',
                screen_ingress_status_request_failed: 'Não foi possível consultar o estado da transmissão no LiveKit.',
                screen_ingress_status_invalid: 'O LiveKit retornou um estado de transmissão inválido.',
                screen_ingress_not_found: 'A transmissão ainda não foi encontrada no LiveKit.',
                screen_ingress_error: 'O LiveKit informou uma falha na transmissão.',
                screen_authorization_expired: 'A autorização expirou antes da confirmação da transmissão.',
                screen_authorization_rejected: 'A autorização da transmissão foi substituída ou recusada.',
                screen_authorization_required: 'A autorização da transmissão não está disponível.',
                screen_publish_confirmation_timeout: 'A transmissão não ficou pronta a tempo.',
                screen_publish_timeout: 'A transmissão não ficou pronta a tempo.',
                screen_target_changed: 'A tela ou janela selecionada não está mais disponível.',
                screen_pipeline_start_failed: 'Não foi possível iniciar o pipeline de compartilhamento.',
                screen_pipeline_exited: 'O pipeline de compartilhamento foi interrompido.',
            };
            const code = typeof error?.message === 'string' && /^screen_[a-z0-9_]+$/.test(error.message) ? error.message : 'screen_publish_failed';
            setPhase('error'); setStatus(`${messages[code] || 'Não foi possível iniciar o compartilhamento.'} Código: ${code}.`, true);
        }
    }

    startButton?.addEventListener('click', () => void start());
    window.addEventListener('semyra:host-ready', (event) => {
        capabilities = Array.isArray(event.detail?.capabilities) ? event.detail.capabilities : [];
        syncAudioCapability();
        void loadTargets();
    });
    window.addEventListener('semyra:screen-state', (event) => {
        if (active && event.detail?.state === 'preparing') {
            setPhase('preparing'); setStatus('Preparando compartilhamento…');
        }
        if (active && event.detail?.state === 'connecting') {
            setPhase('connecting'); setStatus('Conectando transmissão…');
        }
        if (active && event.detail?.state === 'failed') {
            const code = typeof event.detail?.errorCode === 'string' && /^screen_[a-z0-9_]+$/.test(event.detail.errorCode)
                ? event.detail.errorCode : 'screen_publish_failed';
            const messages = {
                screen_audio_failed: 'Não foi possível iniciar o áudio do sistema.',
                screen_capture_failed: 'Não foi possível capturar a tela selecionada.',
                screen_encoder_failed: 'Não foi possível iniciar o codificador de vídeo.',
                screen_whip_failed: 'A conexão da transmissão foi interrompida.',
                screen_authorization_expired: 'A autorização da transmissão expirou.',
                screen_authorization_rejected: 'A autorização da transmissão foi substituída ou recusada.',
                screen_authorization_required: 'A autorização da transmissão não está disponível.',
                screen_publish_confirmation_timeout: 'A transmissão não ficou pronta a tempo.',
                screen_publish_timeout: 'A transmissão não ficou pronta a tempo.',
                screen_pipeline_exited: 'O pipeline de compartilhamento foi interrompido.',
            };
            void cleanup(true).then(() => {
                setPhase('error');
                setStatus(`${messages[code] || 'O compartilhamento foi interrompido no Desktop.'} Código: ${code}.`, true);
            });
        }
    });
    document.addEventListener('semyra:transmission-updated', (event) => {
        const value = event.detail?.transmission ?? event.detail;
        const requestStartedAt = Number(event.detail?.requestStartedAt);
        if (active && Number.isFinite(requestStartedAt) && requestStartedAt <= active.startedAt) return;
        if (active && (value?.instanceId !== active.instanceId || value?.revision !== active.revision)) void cleanup(false);
    });
    window.addEventListener('pagehide', () => {
        const values = fencedValues();
        if (!values || !stopUrl) return;
        stopHeartbeat(); window.dispatchEvent(new CustomEvent('semyra:host-leave-request'));
        try { navigator.sendBeacon?.(stopUrl, new URLSearchParams({_token: csrfToken, ...values})); } catch { }
    });

    const existing = window.SemyraDesktopBridge?.getHostSnapshot?.();
    if (existing?.state === 'ready') {
        capabilities = existing.capabilities ?? [];
        syncAudioCapability();
        void loadTargets();
    } else setStatus('Abra esta sala no Semyra Desktop para compartilhar a tela.');
    for (const close of panel.querySelectorAll?.('[data-close-transmission]') ?? [])
        close.addEventListener('click', () => cancelPreview(true));
    setPhase('idle');
    return {start, cleanup, loadTargets, loadPreview, cancelPreview,
        state: () => ({phase, active, targets: targets.slice(), previewGeneration})};
});
