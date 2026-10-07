'use strict';

(() => {
    const shell = document.querySelector('[data-room-shell]');
    const tabs = document.querySelector('[data-desktop-publish-tabs]');
    const youtube = document.querySelector('[data-youtube-source-panel]');
    const iptv = document.querySelector('[data-iptv-source-panel]');
    const source = document.querySelector('[data-room-iptv-source]');
    const search = document.querySelector('[data-room-iptv-search]');
    const group = document.querySelector('[data-room-iptv-group]');
    const results = document.querySelector('[data-room-iptv-results]');
    const status = document.querySelector('[data-room-iptv-status]');
    if (!shell || !tabs || !youtube || !iptv || !source || !group || !search || !results || !status) return;
    let enabled = false;
    let pendingChannelId = null;
    let generation = 0;
    const request = (detail) => window.dispatchEvent(new CustomEvent('semyra:iptv-request', {detail}));
    const publishStatus = (detail) => {
        if (!detail?.ok) return 'Falha ao publicar o canal.';
        if (detail.state === 'streaming') return 'Transmitindo.';
        if (detail.state === 'reconnecting') return 'Reconectando\u2026';
        if (detail.state === 'preparing') return 'Preparando transmiss\u00e3o\u2026';
        if (detail.state === 'idle') return 'Parado.';
        if (detail.state === 'failed') {
            return detail.errorCode === 'whip_runtime_unavailable'
                ? 'Runtime WHIP indispon\u00edvel.'
                : `N\u00e3o foi poss\u00edvel publicar (${detail.errorCode || 'publish_failed'}).`;
        }
        return 'Publicando canal ao vivo.';
    };
    const show = (mode) => {
        const isIptv = mode === 'iptv';
        youtube.hidden = isIptv;
        iptv.hidden = !isIptv;
        youtube.querySelectorAll('input').forEach((input) => { input.disabled = isIptv; });
        document.querySelector('[data-transmission-kicker]').textContent = isIptv ? 'IPTV local' : 'YouTube';
        document.querySelector('.room-dialog-actions button[type="submit"]').hidden = isIptv;
        if (isIptv) request({action: 'list'});
    };
    tabs.addEventListener('click', (event) => {
        const button = event.target.closest('[data-source-tab]');
        if (button) show(button.dataset.sourceTab);
    });
    document.querySelector('[data-room-iptv-find]').addEventListener('click', () => {
        const sourceId = Number(source.value);
        if (sourceId > 0) request({action: 'search', sourceId, query: search.value, group: group.value || null, offset: 0, limit: 50});
    });
    source.addEventListener('change', () => {
        const sourceId = Number(source.value);
        if (sourceId > 0) request({action: 'groups', sourceId});
    });
    results.addEventListener('click', async (event) => {
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
            if (!response.ok || currentGeneration !== generation || !payload.transmission) throw new Error();
            pendingChannelId = channelId;
            document.dispatchEvent(new CustomEvent('semyra:presence-updated', {detail: {transmission: payload.transmission}}));
            window.dispatchEvent(new CustomEvent('semyra:transmission-updated', {detail: payload.transmission}));
            status.textContent = 'Autorizando publicação…';
        } catch { status.textContent = 'Não foi possível iniciar a transmissão.'; }
    });
    window.addEventListener('semyra:host-ready', (event) => {
        const caps = event.detail?.capabilities;
        enabled = Array.isArray(caps) && caps.includes('livekit.publish') && caps.includes('iptv.catalog');
        tabs.hidden = !enabled;
        if (enabled) request({action: 'list'});
    });
    window.addEventListener('semyra:host-authorized', () => {
        if (pendingChannelId !== null) request({action: 'publish-start', channelId: pendingChannelId});
    });
    window.addEventListener('semyra:iptv-result', (event) => {
        const detail = event.detail;
        if (detail.action === 'list' && detail.ok) {
            source.replaceChildren(...detail.sources.map((item) => Object.assign(document.createElement('option'), {value: item.id, textContent: item.name})));
            if (detail.sources.length > 0) request({action: 'groups', sourceId: detail.sources[0].id});
        } else if (detail.action === 'groups' && detail.ok) {
            group.replaceChildren(Object.assign(document.createElement('option'), {value: '', textContent: 'Todos'}),
                ...detail.groups.map((name) => Object.assign(document.createElement('option'), {value: name, textContent: name})));
        } else if (detail.action === 'search' && detail.ok) {
            results.replaceChildren(...detail.channels.map((channel) => {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'room-secondary-button'; button.dataset.channelId = channel.id;
                button.textContent = `Transmitir ${channel.name}`; return button;
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
