'use strict';

(() => {
    const shell = document.querySelector('[data-room-shell]');
    const sourceTabs = document.querySelector('[data-desktop-publish-tabs]');
    const youtubePanel = document.querySelector('[data-youtube-source-panel]');
    const panel = document.querySelector('[data-iptv-source-panel-modern]');
    const controls = document.querySelector('[data-room-iptv-controls-modern]');
    const unavailable = document.querySelector('[data-room-iptv-unavailable-modern]');
    const vodUnavailable = document.querySelector('[data-room-vod-unavailable]');
    const sourceSelect = document.querySelector('[data-room-iptv-source-modern]');
    const status = document.querySelector('[data-room-iptv-status-modern]') || document.querySelector('[data-room-iptv-status]');
    if (!shell || !sourceTabs || !youtubePanel || !panel || !status) return;

    const PAGE = {live: 30, movies: 24, series: 24};
    const state = {
        canPublish: shell.dataset.canPublishIptv === '1', catalog: false, livePublish: false, vodPublish: false,
        sourceMode: 'youtube', tab: 'live', sources: [], sourceId: null, pending: null, eofHandled: false,
        offset: {live: 0, movies: 0, series: 0}, hasMore: {live: false, movies: false, series: false},
        generation: 0, requestTokens: new Map(), selectedSeries: null,
    };

    const el = (selector) => panel.querySelector(selector);
    const request = (detail) => window.dispatchEvent(new CustomEvent('semyra:iptv-request', {detail}));
    const activeSource = () => state.sources.find((item) => item.id === state.sourceId) || null;
    const token = (scope) => {
        const value = `${scope}-${++state.generation}`;
        state.requestTokens.set(scope, value);
        return value;
    };
    const current = (scope, value) => state.requestTokens.get(scope) === value;
    const errorMessage = (code) => ({
        premium_required: 'Transmitir IPTV para uma sala requer Premium.',
        whip_runtime_unavailable: 'O runtime de publicação WHIP não está disponível.',
        vod_publish_unavailable: 'Este Desktop ainda não pode publicar conteúdo sob demanda.',
        vod_container_unsupported: 'O formato deste conteúdo ainda não pode ser transmitido.',
        vod_codec_unsupported: 'Os codecs deste conteúdo ainda não podem ser transmitidos.',
        provider_connect_failed: 'Não foi possível acessar o conteúdo no provider.',
        livekit_unavailable: 'O serviço de transmissão está temporariamente indisponível.',
        host_session_expired: 'A autorização expirou. Tente iniciar novamente.',
        host_authorization_expired: 'A autorização expirou. Tente iniciar novamente.',
        transmission_changed: 'A transmissão da sala mudou. Tente novamente.',
        ingress_create_failed: 'Não foi possível preparar a transmissão.',
        invalid_request: 'A solicitação de transmissão é inválida.',
        media_publish_failed: 'Não foi possível publicar o conteúdo.',
        publish_failed: 'Não foi possível publicar o conteúdo.',
    }[code] || 'Não foi possível concluir a publicação.');

    const publishStatus = (detail) => {
        if (!detail?.ok) return errorMessage(detail?.errorCode || detail?.error);
        if (detail.state === 'streaming') return 'Transmitindo…';
        if (detail.state === 'reconnecting') return 'Reconectando…';
        if (detail.state === 'preparing') return 'Preparando…';
        if (detail.state === 'ended') return 'Conteúdo finalizado.';
        if (detail.state === 'idle') return 'Parado.';
        if (detail.state === 'failed') return errorMessage(detail.errorCode);
        return 'Publicando…';
    };

    const normalizeStartedTransmission = (transmission) => {
        if (transmission?.source !== 'iptv'
            || !['live', 'vod'].includes(transmission?.media_mode)
            || typeof transmission?.instance_id !== 'string'
            || !/^[a-f0-9]{32}$/.test(transmission.instance_id)
            || !Number.isSafeInteger(transmission?.revision)
            || transmission.revision < 1
            || typeof transmission?.owner_name !== 'string'
            || typeof transmission?.is_owner !== 'boolean') return null;
        return {
            source: 'iptv', instanceId: transmission.instance_id, videoId: null,
            revision: transmission.revision, ownerName: transmission.owner_name,
            isOwner: transmission.is_owner, mediaMode: transmission.media_mode,
            playback: {state: 'playing', positionMs: null, revision: 1, atLiveEdge: false,
                liveEdgePositionMs: null, liveSyncPositionMs: null, liveSyncDelayMs: null},
        };
    };

    function make(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function setBusy(kind, busy) {
        const list = el(`[data-iptv-results-modern="${kind}"]`);
        if (!list) return;
        list.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (!busy) return;
        list.replaceChildren(...Array.from({length: kind === 'live' ? 8 : 10}, () => make('div', `room-iptv-skeleton ${kind === 'live' ? '' : 'is-poster'}`)));
    }

    function empty(kind, message) {
        const list = el(`[data-iptv-results-modern="${kind}"]`);
        if (!list) return;
        const box = make('div', 'room-iptv-empty');
        box.append(make('strong', '', message), make('span', '', 'Tente ajustar a busca ou selecionar outra categoria.'));
        list.replaceChildren(box);
        list.setAttribute('aria-busy', 'false');
    }

    function fallbackImage(container, label) {
        const fallback = make('span', 'room-iptv-image-fallback', (label.trim()[0] || 'S').toUpperCase());
        container.replaceChildren(fallback);
    }

    function image(container, url, label) {
        if (typeof url !== 'string' || !/^https?:\/\//i.test(url)) return fallbackImage(container, label);
        const img = make('img');
        img.alt = '';
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        img.addEventListener('error', () => fallbackImage(container, label), {once: true});
        img.src = url;
        container.append(img);
    }

    function liveCard(channel) {
        const card = make('article', 'room-iptv-live-card');
        const art = make('div', 'room-iptv-channel-art'); image(art, channel.logoUrl, channel.name);
        const copy = make('div', 'room-iptv-card-copy');
        copy.append(make('strong', '', channel.name), make('span', '', channel.groupName || 'TV ao vivo'));
        const button = make('button', 'room-text-button', 'Transmitir');
        button.type = 'button'; button.dataset.publishKind = 'live'; button.dataset.mediaId = String(channel.id);
        card.append(art, copy, button); return card;
    }

    function posterCard(item, kind) {
        const card = make('article', 'room-iptv-poster-card');
        const poster = make('div', 'room-iptv-poster'); image(poster, item.posterUrl, item.name);
        const content = make('div', 'room-iptv-poster-content');
        const title = make('h3', 'room-iptv-poster-title', item.name); title.title = item.name;
        const meta = make('p', 'room-iptv-poster-meta', [item.year, item.rating ? `★ ${item.rating}` : null].filter(Boolean).join(' · ') || (kind === 'movie' ? 'Filme' : 'Série'));
        const action = make('button', 'room-text-button room-iptv-card-action', kind === 'movie' ? 'Transmitir' : 'Ver episódios');
        action.type = 'button'; action.dataset.mediaId = String(item.id);
        if (kind === 'movie') action.dataset.publishKind = 'movie';
        else { action.dataset.itemKind = 'series'; action._catalogItem = item; }
        content.append(title, meta, action); card.append(poster, content);
        return card;
    }

    function renderPage(kind, items, hasMore) {
        const list = el(`[data-iptv-results-modern="${kind}"]`);
        if (!list) return;
        list.setAttribute('aria-busy', 'false');
        state.hasMore[kind] = hasMore;
        const page = Math.floor(state.offset[kind] / PAGE[kind]) + 1;
        el(`[data-iptv-page="${kind}"]`).textContent = `Página ${page} · ${items.length} resultados`;
        el(`[data-iptv-previous="${kind}"]`).disabled = state.offset[kind] === 0;
        el(`[data-iptv-next="${kind}"]`).disabled = !hasMore;
        if (!items.length) return empty(kind, kind === 'live' ? 'Nenhum canal encontrado.' : kind === 'movies' ? 'Nenhum filme encontrado.' : 'Nenhuma série encontrada.');
        list.replaceChildren(...items.map((item) => kind === 'live' ? liveCard(item) : posterCard(item, kind === 'movies' ? 'movie' : 'series')));
    }

    function load(kind, reset = false) {
        const source = activeSource();
        if (!source || !state.canPublish || !state.livePublish) return;
        if (reset) state.offset[kind] = 0;
        if (kind !== 'live' && source.type !== 'xtream') return empty(kind, kind === 'movies' ? 'Nenhum filme encontrado.' : 'Nenhuma série encontrada.');
        if (kind !== 'live' && !state.vodPublish) {
            vodUnavailable.hidden = false;
            return empty(kind, 'Este Desktop ainda não pode publicar conteúdo sob demanda.');
        }
        setBusy(kind, true);
        const scope = `search-${kind}`; const clientRequestId = token(scope);
        const query = el(`[data-iptv-query="${kind}"]`)?.value || '';
        const category = el(`[data-iptv-category="${kind}"]`)?.value || null;
        request(kind === 'live'
            ? {action: 'search', sourceId: source.id, query, group: category, offset: state.offset[kind], limit: PAGE[kind], clientRequestId}
            : {action: kind === 'movies' ? 'vod-movies-search' : 'vod-series-search', sourceId: source.id, query, category, offset: state.offset[kind], limit: PAGE[kind], clientRequestId});
    }

    function loadFilters(kind) {
        const source = activeSource(); if (!source) return;
        const scope = `filters-${kind}`; const clientRequestId = token(scope);
        request(kind === 'live' ? {action: 'groups', sourceId: source.id, clientRequestId}
            : {action: 'vod-categories', sourceId: source.id, mediaType: kind === 'movies' ? 'movie' : 'series', clientRequestId});
    }

    function selectTab(kind, focus = false) {
        if (!['live', 'movies', 'series'].includes(kind)) return;
        state.tab = kind;
        panel.querySelectorAll('[data-iptv-tab]').forEach((button) => {
            const selected = button.dataset.iptvTab === kind;
            button.classList.toggle('is-active', selected); button.setAttribute('aria-selected', selected ? 'true' : 'false');
            button.tabIndex = selected ? 0 : -1; if (selected && focus) button.focus();
        });
        panel.querySelectorAll('[data-iptv-panel]').forEach((item) => { item.hidden = item.dataset.iptvPanel !== kind; });
        if (kind === 'series') closeSeriesDetail();
        loadFilters(kind); load(kind, true);
    }

    function resetSource() {
        state.generation += 1; state.requestTokens.clear(); state.selectedSeries = null;
        ['live', 'movies', 'series'].forEach((kind) => { state.offset[kind] = 0; state.hasMore[kind] = false; });
        selectTab('live');
    }

    function showSource(mode) {
        state.sourceMode = mode === 'iptv' ? 'iptv' : 'youtube';
        const iptv = state.sourceMode === 'iptv';
        youtubePanel.hidden = iptv; panel.hidden = !iptv;
        youtubePanel.querySelectorAll('input, button').forEach((control) => { control.disabled = iptv; });
        document.querySelector('[data-transmission-kicker]').textContent = iptv ? 'IPTV' : 'YouTube';
        sourceTabs.querySelectorAll('[data-source-tab]').forEach((button) => {
            const selected = button.dataset.sourceTab === state.sourceMode;
            button.classList.toggle('is-active', selected); button.setAttribute('aria-selected', selected ? 'true' : 'false');
            button.tabIndex = selected ? 0 : -1;
        });
        if (iptv && state.catalog && state.livePublish && state.sources.length === 0 && state.canPublish) request({action: 'list'});
    }

    function applyCapabilities(capabilities) {
        state.catalog = Array.isArray(capabilities) && capabilities.includes('iptv.catalog');
        state.livePublish = state.catalog && capabilities.includes('livekit.publish');
        state.vodPublish = state.livePublish && capabilities.includes('livekit.publish-vod');
        sourceTabs.hidden = !state.catalog;
        if (controls) controls.hidden = state.canPublish && !state.livePublish;
        if (unavailable) unavailable.hidden = !state.canPublish || state.livePublish;
        if (vodUnavailable) vodUnavailable.hidden = !state.livePublish || state.vodPublish || state.tab === 'live';
        if (!state.catalog) showSource('youtube');
    }

    async function beginPublish(kind, mediaId) {
        if (!state.canPublish || !state.livePublish || (kind !== 'live' && !state.vodPublish)) return;
        const publishGeneration = ++state.generation;
        status.textContent = 'Preparando…';
        try {
            const response = await fetch(shell.dataset.iptvTransmissionUrl, {
                method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: new URLSearchParams({_token: shell.dataset.csrfToken, media_mode: kind === 'live' ? 'live' : 'vod'}),
            });
            const payload = await response.json();
            const transmission = normalizeStartedTransmission(payload?.transmission);
            if (!response.ok || publishGeneration !== state.generation || transmission === null) throw new Error(payload?.error || 'publish_failed');
            state.pending = {kind, mediaId}; state.eofHandled = false;
            document.dispatchEvent(new CustomEvent('semyra:presence-updated', {detail: {transmission}}));
            window.dispatchEvent(new CustomEvent('semyra:transmission-updated', {detail: transmission}));
            status.textContent = 'Autorizando…';
        } catch (error) { status.textContent = errorMessage(error?.message); }
    }

    function openSeries(item) {
        state.selectedSeries = item;
        el('[data-iptv-series-browser]').hidden = true;
        const detail = el('[data-iptv-series-detail]'); detail.hidden = false;
        detail.replaceChildren(make('div', 'room-iptv-skeleton is-detail'));
        const clientRequestId = token('series-detail');
        request({action: 'vod-series-details', seriesId: item.id, refresh: false, clientRequestId});
    }

    function closeSeriesDetail() {
        const browser = el('[data-iptv-series-browser]'); const detail = el('[data-iptv-series-detail]');
        if (browser) browser.hidden = false; if (detail) { detail.hidden = true; detail.replaceChildren(); }
        state.selectedSeries = null; token('series-detail');
    }

    function renderSeriesDetail(episodes) {
        const detail = el('[data-iptv-series-detail]'); const series = state.selectedSeries;
        if (!detail || !series) return;
        const back = make('button', 'room-iptv-back', '← Voltar para Séries'); back.type = 'button'; back.dataset.seriesBack = '';
        const hero = make('div', 'room-iptv-series-hero'); const poster = make('div', 'room-iptv-detail-poster'); image(poster, series.posterUrl, series.name);
        const copy = make('div', 'room-iptv-series-copy'); copy.append(make('h3', '', series.name), make('p', 'room-iptv-meta', [series.year, series.rating ? `★ ${series.rating}` : null, series.genre].filter(Boolean).join(' · ')), make('p', '', series.plot || 'Detalhes fornecidos pelo catálogo IPTV.'));
        hero.append(poster, copy); detail.replaceChildren(back, hero);
        if (!episodes.length) { detail.append(make('div', 'room-iptv-empty', 'Nenhum episódio disponível.')); return; }

        const seasons = new Map();
        episodes.forEach((episode) => {
            if (!seasons.has(episode.seasonNumber)) seasons.set(episode.seasonNumber, []);
            seasons.get(episode.seasonNumber).push(episode);
        });
        const seasonNumbers = [...seasons.keys()].sort((left, right) => left - right);
        const tabs = make('div', 'room-iptv-season-tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Temporadas');
        const section = make('section', 'room-iptv-season'); section.id = 'room-iptv-season-episodes';
        section.setAttribute('role', 'tabpanel');
        detail.append(tabs, section);

        const renderSeason = (season) => {
            tabs.querySelectorAll('[data-season-select]').forEach((button) => {
                const selected = Number(button.dataset.seasonSelect) === season;
                button.setAttribute('aria-selected', selected ? 'true' : 'false'); button.tabIndex = selected ? 0 : -1;
                if (selected) section.setAttribute('aria-labelledby', button.id);
            });
            section.replaceChildren(make('h4', '', season === 0 ? 'Especiais' : `Temporada ${season}`));
            const list = make('div', 'room-iptv-episode-list');
            (seasons.get(season) || []).forEach((episode) => {
                const row = make('article', 'room-iptv-episode'); const number = `S${String(episode.seasonNumber).padStart(2, '0')}E${String(episode.episodeNumber || 0).padStart(2, '0')}`;
                const copyNode = make('div'); copyNode.append(make('strong', '', `${number} · ${episode.name}`), make('span', '', episode.duration || 'Episódio'));
                const button = make('button', 'room-text-button', 'Transmitir'); button.type = 'button'; button.dataset.publishKind = 'episode'; button.dataset.mediaId = String(episode.id);
                row.append(copyNode, button); list.append(row);
            });
            section.append(list);
        };
        seasonNumbers.forEach((season, index) => {
            const button = make('button', 'room-iptv-season-tab', season === 0 ? 'Especiais' : `Temporada ${season}`);
            button.type = 'button'; button.dataset.seasonSelect = String(season); button.setAttribute('role', 'tab');
            button.id = `room-iptv-season-tab-${series.id}-${season}`; button.setAttribute('aria-label', season === 0 ? 'Especiais' : `Temporada ${season}`);
            button.setAttribute('aria-controls', section.id); button.setAttribute('aria-selected', index === 0 ? 'true' : 'false'); button.tabIndex = index === 0 ? 0 : -1;
            button.addEventListener('click', () => renderSeason(season)); tabs.append(button);
        });
        tabs.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault(); const currentIndex = seasonNumbers.findIndex((season) => String(season) === document.activeElement?.dataset?.seasonSelect);
            const direction = event.key === 'ArrowRight' ? 1 : -1; const next = (currentIndex + direction + seasonNumbers.length) % seasonNumbers.length;
            renderSeason(seasonNumbers[next]); tabs.querySelectorAll('[data-season-select]')[next]?.focus();
        });
        renderSeason(seasonNumbers[0]);
    }

    sourceTabs.addEventListener('click', (event) => { const button = event.target.closest('[data-source-tab]'); if (button) showSource(button.dataset.sourceTab); });
    sourceSelect?.addEventListener('change', () => { state.sourceId = Number(sourceSelect.value) || null; resetSource(); });
    panel.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-iptv-tab]'); if (tab) return selectTab(tab.dataset.iptvTab);
        const publish = event.target.closest('[data-publish-kind]'); if (publish) return beginPublish(publish.dataset.publishKind, Number(publish.dataset.mediaId));
        const card = event.target.closest('[data-item-kind]');
        if (card) return card.dataset.itemKind === 'movie' ? beginPublish('movie', Number(card.dataset.mediaId)) : openSeries(card._catalogItem);
        if (event.target.closest('[data-series-back]')) return closeSeriesDetail();
        const previous = event.target.closest('[data-iptv-previous]'); const next = event.target.closest('[data-iptv-next]');
        const direction = previous ? -1 : next ? 1 : 0; const kind = previous?.dataset.iptvPrevious || next?.dataset.iptvNext;
        if (direction && kind) {
            state.offset[kind] = Math.max(0, state.offset[kind] + direction * PAGE[kind]);
            el(`[data-iptv-scroller="${kind}"]`)?.scrollTo?.({top: 0, behavior: 'smooth'}); load(kind);
        }
    });
    panel.addEventListener('submit', (event) => { const form = event.target.closest('[data-iptv-form]'); if (!form) return; event.preventDefault(); load(form.dataset.iptvForm, true); });
    panel.querySelector('[role="tablist"]')?.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return; event.preventDefault();
        const tabs = ['live', 'movies', 'series']; const direction = event.key === 'ArrowRight' ? 1 : -1;
        selectTab(tabs[(tabs.indexOf(state.tab) + direction + tabs.length) % tabs.length], true);
    });

    window.addEventListener('semyra:host-ready', (event) => applyCapabilities(event.detail?.state === 'ready' ? event.detail.capabilities : []));
    window.addEventListener('semyra:host-authorized', () => {
        if (!state.pending) return;
        status.textContent = 'Publicando…';
        request(state.pending.kind === 'live' ? {action: 'publish-start', channelId: state.pending.mediaId}
            : {action: 'publish-start', kind: state.pending.kind, mediaId: state.pending.mediaId});
    });
    window.addEventListener('semyra:host-session-error', (event) => { status.textContent = errorMessage(event.detail?.errorCode); });
    window.addEventListener('semyra:iptv-result', (event) => {
        const detail = event.detail; if (!detail) return;
        if (!detail.ok) { status.textContent = errorMessage(detail.error || detail.errorCode); return; }
        if (detail.action === 'list' && sourceSelect) {
            state.sources = detail.sources || []; sourceSelect.replaceChildren(...state.sources.map((item) => Object.assign(make('option'), {value: item.id, textContent: item.name})));
            state.sourceId = state.sources[0]?.id || null; if (state.sourceId) resetSource(); else empty('live', 'Nenhuma fonte IPTV disponível.');
        } else if (detail.action === 'groups' && current('filters-live', detail.clientRequestId)) {
            const select = el('[data-iptv-category="live"]'); select.replaceChildren(Object.assign(make('option'), {value: '', textContent: 'Todos os grupos'}), ...(detail.groups || []).map((name) => Object.assign(make('option'), {value: name, textContent: name})));
        } else if (detail.action === 'vod-categories') {
            const kind = detail.categories?.[0]?.mediaType === 'series' ? 'series' : (detail.categories?.[0]?.mediaType === 'movie' ? 'movies' : state.tab);
            if (!current(`filters-${kind}`, detail.clientRequestId)) return;
            const select = el(`[data-iptv-category="${kind}"]`); select.replaceChildren(Object.assign(make('option'), {value: '', textContent: 'Todas as categorias'}), ...(detail.categories || []).map((item) => Object.assign(make('option'), {value: item.externalCategoryId, textContent: item.name})));
        } else if (detail.action === 'search' && current('search-live', detail.clientRequestId)) renderPage('live', detail.channels || [], detail.hasMore === true);
        else if (detail.action === 'vod-movies-search' && current('search-movies', detail.clientRequestId)) renderPage('movies', detail.items || [], detail.hasMore === true);
        else if (detail.action === 'vod-series-search' && current('search-series', detail.clientRequestId)) renderPage('series', detail.items || [], detail.hasMore === true);
        else if (detail.action === 'vod-series-details' && current('series-detail', detail.clientRequestId)) renderSeriesDetail(detail.episodes || []);
        else if (detail.action === 'publish-start') status.textContent = publishStatus(detail);
    });
    window.addEventListener('semyra:iptv-media-state', (event) => {
        const detail = event.detail; if (detail?.mode !== 'publish') return;
        status.textContent = publishStatus({...detail, ok: true});
        if (detail.mediaKind === 'vod' && detail.state === 'ended' && !state.eofHandled) {
            state.eofHandled = true; window.setTimeout(() => document.querySelector('[data-end-transmission]')?.requestSubmit(), 400);
        }
    });

    const existing = window.SemyraDesktopBridge?.getHostSnapshot?.();
    if (existing?.state === 'ready') applyCapabilities(existing.capabilities);
})();
