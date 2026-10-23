'use strict';

(() => {
    const shell = document.querySelector('[data-room-shell]');
    const tabs = document.querySelector('[data-desktop-publish-tabs]');
    const youtube = document.querySelector('[data-youtube-source-panel]');
    const iptv = document.querySelector('[data-iptv-source-panel]');
    const controls = document.querySelector('[data-room-iptv-controls]');
    const unavailable = document.querySelector('[data-room-iptv-unavailable]');
    const source = document.querySelector('[data-room-iptv-source]');
    const search = document.querySelector('[data-room-iptv-search]');
    const group = document.querySelector('[data-room-iptv-group]');
    const results = document.querySelector('[data-room-iptv-results]');
    const status = document.querySelector('[data-room-iptv-status]');
    if (!shell || !tabs || !youtube || !iptv || !status) return;

    const canPublishIptv = shell.dataset.canPublishIptv === '1';
    let catalogCapable = false;
    let publishCapable = false;
    let enabled = false;
    let catalogRequested = false;
    let pendingChannelId = null;
    let generation = 0;

    const request = (detail) => window.dispatchEvent(new CustomEvent('semyra:iptv-request', {detail}));
    const errorMessage = (errorCode) => ({
        premium_required: 'Transmitir IPTV para uma sala requer Premium.',
        whip_runtime_unavailable: 'O runtime de publicação WHIP não está disponível.',
        livekit_unavailable: 'O serviço de transmissão está temporariamente indisponível.',
        host_session_expired: 'A autorização expirou. Tente iniciar novamente.',
        host_authorization_expired: 'A autorização expirou. Tente iniciar novamente.',
        transmission_changed: 'A transmissão da sala mudou. Tente novamente.',
        ingress_create_failed: 'Não foi possível preparar a transmissão ao vivo.',
        media_publish_failed: 'Não foi possível publicar o canal.',
        publish_failed: 'Não foi possível publicar o canal.',
    }[errorCode] || 'Não foi possível publicar o canal.');
    const publishStatus = (detail) => {
        if (!detail?.ok) return errorMessage(detail?.errorCode);
        if (detail.state === 'streaming') return 'Transmitindo.';
        if (detail.state === 'reconnecting') return 'Reconectando…';
        if (detail.state === 'preparing') return 'Preparando transmissão…';
        if (detail.state === 'idle') return 'Parado.';
        if (detail.state === 'failed') return errorMessage(detail.errorCode);
        return 'Publicando…';
    };
    const requestCatalogOnce = () => {
        if (!enabled || catalogRequested) return;
        catalogRequested = true;
        request({action: 'list'});
    };
    const show = (mode) => {
        const isIptv = mode === 'iptv';
        youtube.hidden = isIptv;
        iptv.hidden = !isIptv;
        youtube.querySelectorAll('input').forEach((input) => { input.disabled = isIptv; });
        document.querySelector('[data-transmission-kicker]').textContent = isIptv ? 'IPTV local' : 'YouTube';
        document.querySelector('.room-dialog-actions button[type="submit"]').hidden = isIptv;
        if (isIptv) requestCatalogOnce();
    };
    const applyCapabilities = (caps) => {
        const nextCatalogCapable = Array.isArray(caps) && caps.includes('iptv.catalog');
        const nextPublishCapable = nextCatalogCapable && caps.includes('livekit.publish');
        const nextEnabled = canPublishIptv && nextPublishCapable;
        if (!nextEnabled) catalogRequested = false;
        catalogCapable = nextCatalogCapable;
        publishCapable = nextPublishCapable;
        enabled = nextEnabled;
        tabs.hidden = !catalogCapable;
        if (controls) controls.hidden = canPublishIptv && !publishCapable;
        if (unavailable) unavailable.hidden = !canPublishIptv || publishCapable;
        if (!catalogCapable) show('youtube');
        requestCatalogOnce();
    };

    tabs.addEventListener('click', (event) => {
        const button = event.target.closest('[data-source-tab]');
        if (button) show(button.dataset.sourceTab);
    });
    document.querySelector('[data-room-iptv-find]')?.addEventListener('click', () => {
        if (!enabled) return;
        const sourceId = Number(source.value);
        if (sourceId > 0) request({action: 'search', sourceId, query: search.value, group: group.value || null, offset: 0, limit: 50});
    });
    source?.addEventListener('change', () => {
        if (!enabled) return;
        const sourceId = Number(source.value);
        if (sourceId > 0) request({action: 'groups', sourceId});
    });
    results?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-channel-id]');
        if (!button || !enabled) return;
        const channelId = Number(button.dataset.channelId);
        const currentGeneration = ++generation;
        status.textContent = 'Preparando transmissão…';
        try {
            const response = await fetch(shell.dataset.iptvTransmissionUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: new URLSearchParams({_token: shell.dataset.csrfToken}),
            });
            const payload = await response.json();
            if (!response.ok || currentGeneration !== generation || !payload.transmission) {
                throw new Error(payload?.error || 'publish_failed');
            }
            pendingChannelId = channelId;
            document.dispatchEvent(new CustomEvent('semyra:presence-updated', {detail: {transmission: payload.transmission}}));
            window.dispatchEvent(new CustomEvent('semyra:transmission-updated', {detail: payload.transmission}));
            status.textContent = 'Autorizando publicação…';
        } catch (error) {
            status.textContent = errorMessage(error?.message);
        }
    });
    window.addEventListener('semyra:host-ready', (event) => {
        applyCapabilities(event.detail?.state === 'ready' ? event.detail.capabilities : []);
    });
    window.addEventListener('semyra:host-authorized', () => {
        if (enabled && pendingChannelId !== null) {
            status.textContent = 'Publicando…';
            request({action: 'publish-start', channelId: pendingChannelId});
        }
    });
    window.addEventListener('semyra:host-session-error', (event) => {
        status.textContent = errorMessage(event.detail?.errorCode);
    });
    window.addEventListener('semyra:iptv-result', (event) => {
        const detail = event.detail;
        if (!enabled) return;
        if (!detail?.ok) {
            status.textContent = errorMessage(detail?.errorCode);
            return;
        }
        if (detail.action === 'list' && source) {
            source.replaceChildren(...detail.sources.map((item) => Object.assign(document.createElement('option'), {value: item.id, textContent: item.name})));
            if (detail.sources.length > 0) request({action: 'groups', sourceId: detail.sources[0].id});
        } else if (detail.action === 'groups' && group) {
            group.replaceChildren(Object.assign(document.createElement('option'), {value: '', textContent: 'Todos'}),
                ...detail.groups.map((name) => Object.assign(document.createElement('option'), {value: name, textContent: name})));
        } else if (detail.action === 'search' && results) {
            results.replaceChildren(...detail.channels.map((channel) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'room-secondary-button';
                button.dataset.channelId = channel.id;
                button.textContent = `Transmitir ${channel.name}`;
                return button;
            }));
        } else if (detail.action === 'publish-start') {
            status.textContent = publishStatus(detail);
        }
    });
    window.addEventListener('semyra:iptv-media-state', (event) => {
        if (event.detail?.mode === 'publish') status.textContent = publishStatus({...event.detail, ok: true});
    });
    const existing = window.SemyraDesktopBridge?.getHostSnapshot?.();
    if (existing?.state === 'ready') {
        window.dispatchEvent(new CustomEvent('semyra:host-ready', {detail: existing}));
    }
})();
