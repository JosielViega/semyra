(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }
    api.createIptvCatalog(root).start();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const VISUAL_STATES = ['idle', 'preparing', 'streaming', 'reconnecting', 'failed'];
    const ACTIVE_STATES = ['preparing', 'streaming', 'reconnecting'];
    const SETTINGS_STATES = Object.freeze({
        'adding-file': ['Importando sua IPTV…', 'Lendo o arquivo e carregando os canais.'],
        'adding-url': ['Carregando sua IPTV…', 'Conectando à fonte e importando os canais.'],
        'selecting-source': ['Carregando canais…', 'Preparando esta fonte para você.'],
        'refreshing-source': ['Atualizando catálogo…', 'Buscando a versão mais recente dos canais.'],
        'removing-source': ['Removendo fonte…', 'Apagando o catálogo local deste dispositivo.'],
    });

    function mediaStatusLabel(state, errorCode) {
        if (state === 'idle') return 'Escolha um canal';
        if (state === 'preparing') return 'Preparando canal…';
        if (state === 'streaming') return 'Reproduzindo agora';
        if (state === 'reconnecting') return 'Reconectando…';
        if (['media_runtime_unavailable', 'local_view_runtime_unavailable'].includes(errorCode)) return 'Player local indisponível neste dispositivo.';
        if (errorCode === 'local_view_playlist_unavailable') return 'Este canal não forneceu mídia compatível.';
        return 'Não foi possível abrir este canal.';
    }

    function validPlaybackUrl(value) {
        return typeof value === 'string'
            && /^\/__desktop\/playback\/[a-f0-9]{64}\/index\.m3u8$/.test(value);
    }

    function localViewAvailable(snapshot) {
        return Boolean(snapshot && Array.isArray(snapshot.capabilities)
            && snapshot.capabilities.includes('iptv.local-view'));
    }

    function channelMonogram(name) {
        const words = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (words.length === 0) return 'TV';
        const letters = words.length === 1 ? words[0].slice(0, 2) : words[0][0] + words[1][0];
        return letters.toLocaleUpperCase('pt-BR');
    }

    function shouldRenderRemoteLogo() {
        return false;
    }

    function createHlsPlaybackController(target, video, onFatal, onAutoplayBlocked) {
        let hls = null;
        let active = false;
        let lastVolume = .8;
        const autoplayBlocked = typeof onAutoplayBlocked === 'function' ? onAutoplayBlocked : function () {};
        if (typeof video.addEventListener === 'function') {
            video.addEventListener('error', fail);
        }

        function resetMedia() {
            if (hls) {
                hls.destroy();
                hls = null;
            }
            video.pause();
            video.removeAttribute('src');
            video.load();
            autoplayBlocked(false);
        }

        function destroy() {
            active = false;
            resetMedia();
        }

        function fail() {
            if (!active) return;
            active = false;
            resetMedia();
            onFatal();
        }

        function play() {
            if (!active) return Promise.resolve(false);
            let result;
            try {
                result = video.play();
            } catch (_) {
                autoplayBlocked(true);
                return Promise.resolve(false);
            }
            return Promise.resolve(result).then(function () {
                autoplayBlocked(false);
                return true;
            }).catch(function () {
                autoplayBlocked(true);
                return false;
            });
        }

        function attach(playbackUrl) {
            if (!validPlaybackUrl(playbackUrl)) return false;
            destroy();
            active = true;
            if (target.Hls && typeof target.Hls.isSupported === 'function' && target.Hls.isSupported()) {
                hls = new target.Hls({liveDurationInfinity: true, enableWorker: true});
                hls.on(target.Hls.Events.ERROR, function (_, data) {
                    if (data && data.fatal) fail();
                });
                hls.on(target.Hls.Events.MANIFEST_PARSED, play);
                hls.loadSource(playbackUrl);
                hls.attachMedia(video);
                return true;
            }
            if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = playbackUrl;
                play();
                return true;
            }
            fail();
            return false;
        }

        function setVolume(value) {
            const volume = Math.min(1, Math.max(0, Number(value)));
            video.volume = Number.isFinite(volume) ? volume : .8;
            if (video.volume > 0) {
                lastVolume = video.volume;
                video.muted = false;
            } else {
                video.muted = true;
            }
            return {volume: video.volume, muted: video.muted};
        }

        function toggleMuted() {
            if (video.muted || video.volume === 0) {
                video.volume = lastVolume || .8;
                video.muted = false;
            } else {
                if (video.volume > 0) lastVolume = video.volume;
                video.muted = true;
            }
            return {volume: video.volume, muted: video.muted};
        }

        video.volume = .8;
        return Object.freeze({attach, destroy, play, setVolume, toggleMuted});
    }

    function createIptvCatalog(target) {
        const document = target && target.document;
        const root = document && document.querySelector('[data-iptv-catalog]');
        let sources = [];
        let selectedSourceId = null;
        let offset = 0;
        let mediaAvailable = false;
        let player = null;
        let currentChannelId = null;
        let currentChannelName = '';
        let mediaState = 'idle';
        let hideTimer = null;
        let volumeAdjusting = false;
        let activated = false;
        let fallbackTimer = null;
        let requestSequence = 0;
        let listRequestToken = null;
        let groupsRequestToken = null;
        let searchRequestToken = null;
        let settingsOperation = null;
        let navigationStopRequested = false;
        const groupsBySource = new Map();
        const limit = 50;

        function element(selector) {
            return root && root.querySelector(selector);
        }

        function nextRequestToken(category) {
            requestSequence += 1;
            return category + '-' + requestSequence;
        }

        function request(action, detail, clientRequestId) {
            target.dispatchEvent(new target.CustomEvent('semyra:iptv-request', {
                detail: Object.assign({action}, detail || {}, clientRequestId ? {clientRequestId} : {}),
            }));
        }

        function node(tag, className, value) {
            const created = document.createElement(tag);
            if (className) created.className = className;
            if (value !== undefined) created.textContent = value;
            return created;
        }

        function feedback(message, error) {
            const output = element('[data-iptv-feedback]');
            if (!output) return;
            output.textContent = message || '';
            output.dataset.state = error ? 'error' : 'ok';
        }

        function settingsControls(disabled) {
            const content = element('[data-iptv-settings-content]');
            if (content) {
                content.inert = disabled;
                if (disabled) content.setAttribute('inert', '');
                else content.removeAttribute('inert');
                if (typeof content.querySelectorAll === 'function') {
                    content.querySelectorAll('button, input, select').forEach(function (control) {
                        control.disabled = disabled;
                    });
                }
            }
            const close = element('[data-iptv-settings-close]');
            if (close) close.disabled = disabled;
        }

        function renderSettingsState(state) {
            const settings = element('[data-iptv-settings]');
            const loader = element('[data-iptv-settings-loader]');
            const busy = state !== 'idle';
            if (settings) {
                settings.dataset.settingsState = state;
                settings.setAttribute('aria-busy', busy ? 'true' : 'false');
                if (busy) settings.scrollTop = 0;
            }
            settingsControls(busy);
            if (!loader) return;
            loader.hidden = !busy;
            if (!busy) return;
            const copy = SETTINGS_STATES[state];
            const title = element('[data-iptv-settings-loader-title]');
            const description = element('[data-iptv-settings-loader-copy]');
            if (title) title.textContent = copy[0];
            if (description) description.textContent = copy[1];
        }

        function beginSettingsOperation(state, trigger, sourceId) {
            if (settingsOperation || !SETTINGS_STATES[state]) return false;
            settingsOperation = {
                state,
                trigger,
                sourceId: sourceId || null,
                tokens: new Map(),
                pending: new Set(),
                closeOnComplete: false,
                successMessage: '',
            };
            feedback('', false);
            renderSettingsState(state);
            return true;
        }

        function operationRequest(action, detail) {
            if (!settingsOperation) return null;
            const token = nextRequestToken(action);
            settingsOperation.tokens.set(action, token);
            request(action, detail, token);
            return token;
        }

        function operationOwns(detail) {
            return Boolean(settingsOperation && settingsOperation.tokens.get(detail.action) === detail.clientRequestId);
        }

        function finishSettingsOperation(message, error, closeAfter) {
            if (!settingsOperation) return;
            const focusTarget = settingsOperation.trigger;
            settingsOperation = null;
            renderSettingsState('idle');
            feedback(message || '', Boolean(error));
            if (closeAfter) {
                closeSettings(true);
                const opener = element('[data-iptv-settings-open]');
                if (opener && typeof opener.focus === 'function') opener.focus();
            } else if (focusTarget && typeof focusTarget.focus === 'function' && focusTarget.isConnected !== false) {
                focusTarget.focus();
            }
        }

        function openSettings() {
            const settings = element('[data-iptv-settings]');
            if (!settings) return;
            if (typeof settings.showModal === 'function') settings.showModal();
            else settings.setAttribute('open', '');
            element('[data-iptv-settings-open]')?.setAttribute('aria-expanded', 'true');
        }

        function closeSettings(force) {
            const settings = element('[data-iptv-settings]');
            if (!settings || (settingsOperation && !force)) return;
            if (typeof settings.close === 'function' && settings.open) settings.close();
            else settings.removeAttribute('open');
            element('[data-iptv-settings-open]')?.setAttribute('aria-expanded', 'false');
        }

        function renderExperience() {
            const hasSources = sources.length > 0;
            const loading = element('[data-iptv-source-loading]');
            const onboarding = element('[data-iptv-onboarding]');
            const library = element('[data-iptv-library]');
            const switcher = element('[data-iptv-source-switcher]');
            if (loading) {
                loading.hidden = true;
                loading.setAttribute('aria-busy', 'false');
            }
            if (onboarding) onboarding.hidden = hasSources;
            if (library) library.hidden = !hasSources;
            if (switcher) switcher.hidden = !hasSources;
        }

        function renderSourceSelector() {
            const current = sources.find(function (source) { return source.id === selectedSourceId; });
            const name = element('[data-iptv-current-source]');
            const wrapper = element('[data-iptv-source-select-wrap]');
            const select = element('[data-iptv-source-select]');
            if (name) name.textContent = current ? current.name : '';
            if (!select || !wrapper) return;
            select.replaceChildren();
            sources.forEach(function (source) {
                const option = node('option', '', source.name);
                option.value = String(source.id);
                select.appendChild(option);
            });
            if (current) select.value = String(current.id);
            wrapper.hidden = sources.length < 2;
            if (name) name.hidden = sources.length > 1;
        }

        function sourceStatusLabel(status) {
            if (status === 'ready') return 'Pronta';
            if (status === 'refreshing') return 'Atualizando';
            if (status === 'error') return 'Precisa de atenção';
            return 'Aguardando importação';
        }

        function upsertSource(source) {
            if (!source || !Number.isSafeInteger(source.id)) return;
            const index = sources.findIndex(function (item) { return item.id === source.id; });
            if (index === -1) sources.push(source);
            else sources[index] = Object.assign({}, sources[index], source);
        }

        function renderSources() {
            const list = element('[data-iptv-source-list]');
            if (!list) return;
            renderExperience();
            if (sources.length === 0) {
                list.replaceChildren(node('p', 'iptv-settings-empty', 'Nenhuma fonte adicionada neste dispositivo.'));
                selectedSourceId = null;
                renderSourceSelector();
                return;
            }
            if (!sources.some(function (source) { return source.id === selectedSourceId; })) selectedSourceId = sources[0].id;
            renderSourceSelector();
            const fragment = document.createDocumentFragment();
            sources.forEach(function (source) {
                const card = node('article', 'iptv-source-card' + (source.id === selectedSourceId ? ' is-selected' : ''));
                card.dataset.sourceId = String(source.id);
                const copy = node('div', '');
                copy.appendChild(node('h4', '', source.name));
                const type = source.type === 'm3u_file' ? 'Arquivo local' : 'Lista por URL';
                copy.appendChild(node('p', 'iptv-source-meta', type + ' · ' + source.channelCount.toLocaleString('pt-BR') + ' canais · ' + sourceStatusLabel(source.lastRefreshStatus)));
                if (source.lastRefreshError) copy.appendChild(node('p', 'iptv-source-error', source.lastRefreshError));
                card.appendChild(copy);
                const actions = node('div', 'iptv-source-actions');
                if (source.id === selectedSourceId) {
                    const current = node('span', 'iptv-source-current', 'Em uso');
                    current.setAttribute('role', 'status');
                    actions.appendChild(current);
                }
                [['select', 'Selecionar'], ['refresh', 'Atualizar'], ['remove', 'Remover']].forEach(function (definition) {
                    if (definition[0] === 'select' && source.id === selectedSourceId) return;
                    const button = node('button', '', definition[1]);
                    button.type = 'button';
                    button.dataset.iptvAction = definition[0];
                    if (definition[0] === 'remove') button.className = 'is-danger';
                    actions.appendChild(button);
                });
                card.appendChild(actions);
                fragment.appendChild(card);
            });
            list.replaceChildren(fragment);
            if (settingsOperation) settingsControls(true);
        }

        function renderGroups(groups) {
            const select = element('[data-iptv-search-form] select[name="group"]');
            if (!select) return;
            const selected = select.value;
            const fragment = document.createDocumentFragment();
            const all = node('option', '', 'Todos os grupos');
            all.value = '';
            fragment.appendChild(all);
            groups.forEach(function (group) {
                const option = node('option', '', group);
                option.value = group;
                fragment.appendChild(option);
            });
            select.replaceChildren(fragment);
            select.value = groups.includes(selected) ? selected : '';
            select.disabled = false;
            select.removeAttribute('aria-busy');
        }

        function setCatalogLoading(loading, message) {
            const list = element('[data-iptv-channel-list]');
            if (!list) return;
            list.setAttribute('aria-busy', loading ? 'true' : 'false');
            const form = element('[data-iptv-search-form]');
            const previous = element('[data-iptv-previous]');
            const next = element('[data-iptv-next]');
            const submit = form && form.querySelector ? form.querySelector('button[type="submit"]') : null;
            if (submit) submit.disabled = loading;
            if (previous) previous.disabled = loading || offset === 0;
            if (next && loading) next.disabled = true;
            if (!loading) return;
            const fragment = document.createDocumentFragment();
            for (let index = 0; index < 10; index += 1) fragment.appendChild(node('span', 'iptv-skeleton'));
            list.replaceChildren(fragment);
            const count = element('[data-iptv-result-count]');
            if (count) count.textContent = message || 'Carregando canais…';
        }

        function renderChannelEmpty(message) {
            const empty = node('div', 'iptv-empty-state');
            empty.appendChild(node('span', 'iptv-channel-monogram', 'TV'));
            empty.appendChild(node('p', '', message));
            return empty;
        }

        function updateActiveCards() {
            if (!root || typeof root.querySelectorAll !== 'function') return;
            root.querySelectorAll('[data-iptv-media-start]').forEach(function (card) {
                const active = Number(card.dataset.iptvMediaStart) === currentChannelId && ACTIVE_STATES.includes(mediaState);
                card.classList.toggle('is-active', active);
                card.setAttribute('aria-pressed', active ? 'true' : 'false');
                card.disabled = !mediaAvailable || active;
                if (active && mediaState === 'preparing') card.setAttribute('aria-busy', 'true');
                else card.removeAttribute('aria-busy');
                const badge = card.querySelector('[data-iptv-channel-live]');
                if (badge) badge.hidden = !(active && mediaState === 'streaming');
            });
        }

        function renderChannels(channels, hasMore) {
            const list = element('[data-iptv-channel-list]');
            if (!list) return;
            const fragment = document.createDocumentFragment();
            const form = element('[data-iptv-search-form]');
            const query = form && form.elements.query.value.trim();
            if (channels.length === 0) {
                fragment.appendChild(renderChannelEmpty(query ? 'Nenhum canal encontrado.' : 'Esta fonte ainda não possui canais.'));
            } else {
                channels.forEach(function (channel) {
                    const card = node('button', 'iptv-channel-card');
                    card.type = 'button';
                    card.dataset.iptvMediaStart = String(channel.id);
                    card.disabled = !mediaAvailable || (channel.id === currentChannelId && ACTIVE_STATES.includes(mediaState));
                    card.setAttribute('aria-label', 'Assistir ' + channel.name);
                    card.setAttribute('aria-pressed', 'false');
                    const visual = node('span', 'iptv-channel-visual');
                    visual.appendChild(node('span', 'iptv-channel-monogram', channelMonogram(channel.name)));
                    const live = node('span', 'iptv-channel-live', 'Ao vivo');
                    live.dataset.iptvChannelLive = '';
                    live.hidden = true;
                    visual.appendChild(live);
                    const copy = node('span', 'iptv-channel-copy');
                    copy.appendChild(node('strong', '', channel.name));
                    copy.appendChild(node('span', '', channel.groupName || 'Canal ao vivo'));
                    card.appendChild(visual);
                    card.appendChild(copy);
                    fragment.appendChild(card);
                });
            }
            list.replaceChildren(fragment);
            list.setAttribute('aria-busy', 'false');
            const previous = element('[data-iptv-previous]');
            const next = element('[data-iptv-next]');
            if (previous) previous.disabled = offset === 0;
            if (next) next.disabled = !hasMore;
            const count = element('[data-iptv-result-count]');
            if (count) count.textContent = channels.length === 0 ? '' : channels.length + (hasMore ? '+ canais nesta página' : ' canais nesta página');
            const page = element('[data-iptv-page-label]');
            if (page) page.textContent = 'Página ' + (Math.floor(offset / limit) + 1);
            const submit = form && form.querySelector ? form.querySelector('button[type="submit"]') : null;
            if (submit) submit.disabled = false;
            updateActiveCards();
        }

        function search(message) {
            if (!selectedSourceId) return;
            const form = element('[data-iptv-search-form]');
            searchRequestToken = nextRequestToken('search');
            setCatalogLoading(true, message || 'Carregando canais…');
            request('search', {
                sourceId: selectedSourceId,
                query: form.elements.query.value.trim(),
                group: form.elements.group.value,
                offset,
                limit,
            }, searchRequestToken);
        }

        function selectSource(sourceId) {
            if (!sources.some(function (source) { return source.id === sourceId; })) return null;
            selectedSourceId = sourceId;
            offset = 0;
            renderSources();
            const group = element('[data-iptv-search-form] select[name="group"]');
            if (group) {
                group.value = '';
                const cached = groupsBySource.get(sourceId);
                if (cached) renderGroups(cached);
                else {
                    group.disabled = true;
                    group.setAttribute('aria-busy', 'true');
                    group.replaceChildren();
                    const loading = node('option', '', 'Carregando grupos…');
                    loading.value = '';
                    group.appendChild(loading);
                }
            }
            if (!groupsBySource.has(sourceId)) {
                groupsRequestToken = nextRequestToken('groups');
                request('groups', {sourceId}, groupsRequestToken);
            } else {
                groupsRequestToken = null;
            }
            const sourceSelect = element('[data-iptv-source-select]');
            if (sourceSelect) sourceSelect.disabled = true;
            search('Trocando fonte…');
            return {groups: groupsRequestToken, search: searchRequestToken};
        }

        function loadSourceForOperation(sourceId, closeOnComplete, successMessage) {
            if (!settingsOperation) return;
            const tokens = selectSource(sourceId);
            if (!tokens) {
                finishSettingsOperation('Não foi possível carregar esta fonte.', true, false);
                return;
            }
            settingsOperation.sourceId = sourceId;
            settingsOperation.closeOnComplete = closeOnComplete;
            settingsOperation.successMessage = successMessage || '';
            settingsOperation.pending.clear();
            if (tokens.groups) {
                settingsOperation.tokens.set('groups', tokens.groups);
                settingsOperation.pending.add('groups');
            }
            settingsOperation.tokens.set('search', tokens.search);
            settingsOperation.pending.add('search');
        }

        function settleSourceLoad(action) {
            if (!settingsOperation || !settingsOperation.pending.has(action)) return;
            settingsOperation.pending.delete(action);
            if (settingsOperation.pending.size > 0) return;
            const closeAfter = settingsOperation.closeOnComplete;
            const message = settingsOperation.successMessage;
            finishSettingsOperation(message, false, closeAfter);
        }

        function settingsErrorMessage(state) {
            if (state === 'refreshing-source') return 'Não foi possível atualizar o catálogo.';
            if (state === 'removing-source') return 'Não foi possível remover a fonte.';
            return 'Não foi possível carregar esta fonte.';
        }

        function showAutoplayFallback(show) {
            const button = element('[data-iptv-autoplay]');
            if (button) button.hidden = !show;
        }

        function ensurePlayer() {
            const video = element('[data-iptv-video]');
            if (!video || player) return player;
            player = createHlsPlaybackController(target, video, function () {
                renderMediaState({state: 'failed', errorCode: 'local_view_playback_failed'});
                request('view-stop');
            }, showAutoplayFallback);
            return player;
        }

        function destroyPlayer() {
            if (player) player.destroy();
            showAutoplayFallback(false);
            clearTimeout(hideTimer);
            const stage = element('[data-iptv-player-stage]');
            if (stage) stage.dataset.controlsHidden = 'false';
        }

        function attachPlayer(playbackUrl) {
            const controller = ensurePlayer();
            if (controller && validPlaybackUrl(playbackUrl)) controller.attach(playbackUrl);
        }

        function renderMediaState(detail) {
            mediaState = VISUAL_STATES.includes(detail.state) ? detail.state : 'failed';
            if (Number.isSafeInteger(detail.channelId) && detail.channelId > 0) currentChannelId = detail.channelId;
            if (typeof detail.channelName === 'string' && detail.channelName) currentChannelName = detail.channelName;
            if (mediaState === 'idle') {
                destroyPlayer();
                currentChannelId = null;
                currentChannelName = '';
            } else if (mediaState === 'failed') {
                destroyPlayer();
            }
            const stage = element('[data-iptv-player-stage]');
            const empty = element('[data-iptv-player-empty]');
            const state = element('[data-iptv-player-state]');
            const stateLabel = element('[data-iptv-player-state-label]');
            const error = element('[data-iptv-player-error]');
            const live = element('[data-iptv-live-badge]');
            const status = element('[data-iptv-media-status]');
            const title = element('[data-iptv-current-channel]');
            const stop = element('[data-iptv-media-stop]');
            if (stage) stage.dataset.state = mediaState;
            if (empty) empty.hidden = mediaState !== 'idle';
            if (state) state.hidden = !['preparing', 'reconnecting'].includes(mediaState);
            if (stateLabel) stateLabel.textContent = mediaState === 'reconnecting' ? 'Reconectando…' : 'Preparando canal…';
            if (error) error.hidden = mediaState !== 'failed';
            const errorTitle = element('[data-iptv-error-title]');
            const errorCopy = element('[data-iptv-error-copy]');
            const retry = element('[data-iptv-retry]');
            const runtimeUnavailable = ['media_runtime_unavailable', 'local_view_runtime_unavailable'].includes(detail.errorCode);
            if (errorTitle) errorTitle.textContent = runtimeUnavailable ? 'Player local indisponível.' : 'Não foi possível abrir este canal.';
            if (errorCopy) errorCopy.textContent = runtimeUnavailable
                ? 'Este dispositivo não possui os componentes necessários para a reprodução local.'
                : 'Tente novamente ou escolha outro canal do catálogo.';
            if (retry) retry.hidden = runtimeUnavailable || !currentChannelId;
            if (live) live.hidden = mediaState !== 'streaming';
            if (status) {
                status.textContent = mediaStatusLabel(mediaState, detail.errorCode);
                status.dataset.state = mediaState;
            }
            if (title) title.textContent = currentChannelName || 'Minha IPTV';
            if (stop) stop.disabled = !ACTIVE_STATES.includes(mediaState);
            updateActiveCards();
            showControls();
        }

        function startChannel(channelId, channelName) {
            if (!mediaAvailable || !Number.isSafeInteger(channelId) || channelId < 1) return;
            if (currentChannelId === channelId && ACTIVE_STATES.includes(mediaState)) return;
            currentChannelId = channelId;
            currentChannelName = channelName || 'Canal selecionado';
            renderMediaState({state: 'preparing', channelId, channelName: currentChannelName});
            request('view-start', {channelId});
            element('[data-iptv-player-stage]')?.scrollIntoView?.({behavior: 'smooth', block: 'center'});
        }

        function exitFullscreen() {
            const stage = element('[data-iptv-player-stage]');
            if (document.fullscreenElement === stage && typeof document.exitFullscreen === 'function') {
                Promise.resolve(document.exitFullscreen()).catch(function () {});
            }
        }

        function stopPlayback() {
            destroyPlayer();
            exitFullscreen();
            renderMediaState({state: 'idle'});
            request('view-stop');
        }

        function leavePage() {
            if (navigationStopRequested) return;
            navigationStopRequested = true;
            const shouldStop = mediaAvailable && (currentChannelId !== null || ACTIVE_STATES.includes(mediaState));
            destroyPlayer();
            renderMediaState({state: 'idle'});
            if (shouldStop) request('view-stop');
        }

        function updateAudioUi(state) {
            const volume = element('[data-iptv-volume]');
            const button = element('[data-iptv-mute]');
            const volumeIcon = element('[data-iptv-volume-icon]');
            const mutedIcon = element('[data-iptv-muted-icon]');
            const muted = state.muted || state.volume === 0;
            if (volume) volume.value = String(Math.round(state.volume * 100));
            if (button) {
                button.setAttribute('aria-label', muted ? 'Ativar som' : 'Silenciar');
                button.title = muted ? 'Ativar som' : 'Silenciar';
            }
            if (volumeIcon) volumeIcon.hidden = muted;
            if (mutedIcon) mutedIcon.hidden = !muted;
        }

        function toggleFullscreen() {
            const stage = element('[data-iptv-player-stage]');
            if (!stage) return;
            if (document.fullscreenElement === stage) exitFullscreen();
            else if (typeof stage.requestFullscreen === 'function') Promise.resolve(stage.requestFullscreen()).catch(function () {});
        }

        function updateFullscreenUi() {
            const active = document.fullscreenElement === element('[data-iptv-player-stage]');
            const button = element('[data-iptv-fullscreen]');
            if (button) {
                button.setAttribute('aria-label', active ? 'Sair da tela cheia' : 'Entrar em tela cheia');
                button.title = active ? 'Sair da tela cheia' : 'Entrar em tela cheia';
            }
            const expand = element('[data-iptv-fullscreen-icon]');
            const compress = element('[data-iptv-compress-icon]');
            if (expand) expand.hidden = active;
            if (compress) compress.hidden = !active;
        }

        function showControls() {
            const stage = element('[data-iptv-player-stage]');
            if (!stage) return;
            stage.dataset.controlsHidden = 'false';
            clearTimeout(hideTimer);
            if (mediaState !== 'streaming') return;
            hideTimer = target.setTimeout(function () {
                const focused = typeof stage.contains === 'function' && stage.contains(document.activeElement);
                if (!focused && !volumeAdjusting && mediaState === 'streaming') stage.dataset.controlsHidden = 'true';
            }, 3000);
        }

        function loadSources() {
            listRequestToken = nextRequestToken('list');
            request('list', {}, listRequestToken);
        }

        function isCurrentResult(detail, token) {
            return token === null || detail.clientRequestId === token;
        }

        function onResult(event) {
            const detail = event && event.detail;
            if (!detail || typeof detail.action !== 'string') return;
            if (detail.action === 'list' && !isCurrentResult(detail, listRequestToken)) return;
            if (detail.action === 'groups' && !isCurrentResult(detail, groupsRequestToken)) return;
            if (detail.action === 'search' && !isCurrentResult(detail, searchRequestToken)) return;
            const ownedBySettings = operationOwns(detail);
            if (settingsOperation && settingsOperation.tokens.has(detail.action) && !ownedBySettings) return;
            if (!detail.ok) {
                if (detail.action === 'view-start') renderMediaState({state: 'failed', errorCode: detail.error});
                else if (detail.action === 'search') {
                    setCatalogLoading(false);
                    const list = element('[data-iptv-channel-list]');
                    if (list) list.replaceChildren(renderChannelEmpty('Não foi possível carregar os canais. Tente novamente.'));
                    const sourceSelect = element('[data-iptv-source-select]');
                    if (sourceSelect) sourceSelect.disabled = false;
                } else {
                    if (detail.action === 'groups') {
                        const group = element('[data-iptv-search-form] select[name="group"]');
                        if (group) {
                            group.disabled = false;
                            group.removeAttribute('aria-busy');
                        }
                    }
                    const loading = element('[data-iptv-source-loading]');
                    if (detail.action === 'list' && loading) {
                        loading.hidden = false;
                        loading.setAttribute('aria-busy', 'false');
                        const copy = loading.querySelector ? loading.querySelector('p') : null;
                        if (copy) copy.textContent = 'Não foi possível carregar sua IPTV.';
                    }
                    feedback(detail.error || 'Operação indisponível.', true);
                }
                if (ownedBySettings) finishSettingsOperation(settingsErrorMessage(settingsOperation.state), true, false);
                return;
            }
            if (detail.action === 'list') {
                sources = Array.isArray(detail.sources) ? detail.sources : [];
                renderSources();
                if (selectedSourceId) selectSource(selectedSourceId);
            } else if (detail.action === 'add-url' || detail.action === 'pick-file') {
                if (!ownedBySettings) return;
                if (detail.cancelled) {
                    finishSettingsOperation('', false, false);
                    return;
                }
                if (!detail.source || !Number.isSafeInteger(detail.source.id)) {
                    finishSettingsOperation('Não foi possível carregar esta fonte.', true, false);
                    return;
                }
                upsertSource(detail.source);
                renderSources();
                settingsOperation.sourceId = detail.source.id;
                operationRequest('refresh', {sourceId: detail.source.id});
            } else if (detail.action === 'remove') {
                if (!ownedBySettings) return;
                if (!detail.removed) {
                    finishSettingsOperation('Não foi possível remover a fonte.', true, false);
                    return;
                }
                const removedSourceId = settingsOperation.sourceId;
                const removedActive = removedSourceId === selectedSourceId;
                sources = sources.filter(function (source) { return source.id !== removedSourceId; });
                groupsBySource.clear();
                if (!removedActive) {
                    renderSources();
                    finishSettingsOperation('Fonte removida deste dispositivo.', false, false);
                } else if (sources.length === 0) {
                    selectedSourceId = null;
                    renderSources();
                    const channelList = element('[data-iptv-channel-list]');
                    if (channelList) channelList.replaceChildren();
                    finishSettingsOperation('Fonte removida deste dispositivo.', false, false);
                } else {
                    selectedSourceId = sources[0].id;
                    renderSources();
                    loadSourceForOperation(selectedSourceId, false, 'Fonte removida. Outra fonte está em uso.');
                }
            } else if (detail.action === 'refresh') {
                if (!ownedBySettings || !detail.source || !Number.isSafeInteger(detail.source.id)) return;
                const operationState = settingsOperation.state;
                upsertSource(detail.source);
                groupsBySource.delete(detail.source.id);
                renderSources();
                if (operationState === 'adding-file' || operationState === 'adding-url') {
                    loadSourceForOperation(detail.source.id, true, '');
                } else if (detail.source.id === selectedSourceId) {
                    loadSourceForOperation(detail.source.id, false, '✓ Catálogo atualizado.');
                } else {
                    finishSettingsOperation('✓ Catálogo atualizado.', false, false);
                }
            } else if (detail.action === 'groups') {
                const groups = Array.isArray(detail.groups) ? detail.groups : [];
                groupsBySource.set(selectedSourceId, groups);
                renderGroups(groups);
                if (ownedBySettings) settleSourceLoad('groups');
            } else if (detail.action === 'search') {
                if (Number.isSafeInteger(detail.offset)) offset = detail.offset;
                renderChannels(detail.channels || [], detail.hasMore);
                const sourceSelect = element('[data-iptv-source-select]');
                if (sourceSelect) sourceSelect.disabled = false;
                if (ownedBySettings) settleSourceLoad('search');
            } else if (['view-start', 'view-stop', 'view-status'].includes(detail.action)) {
                if (detail.action === 'view-start' && detail.playbackUrl) attachPlayer(detail.playbackUrl);
                if (detail.action === 'view-stop') destroyPlayer();
                renderMediaState(detail);
            }
        }

        function onMediaState(event) {
            if (event && event.detail) renderMediaState(event.detail);
        }

        function activate(event) {
            const snapshot = event && event.detail
                ? event.detail
                : target.SemyraDesktopBridge && target.SemyraDesktopBridge.getHostSnapshot();
            mediaAvailable = localViewAvailable(snapshot);
            const notice = element('[data-iptv-browser-notice]');
            const panel = element('[data-iptv-desktop-panel]');
            const loading = element('[data-iptv-source-loading]');
            activated = true;
            clearTimeout(fallbackTimer);
            if (notice) notice.hidden = true;
            if (panel) panel.hidden = false;
            if (loading) loading.hidden = false;
            renderMediaState({state: mediaAvailable ? 'idle' : 'failed', errorCode: mediaAvailable ? null : 'media_runtime_unavailable'});
            loadSources();
            if (mediaAvailable) request('view-status');
        }

        function bind(selector, type, listener) {
            element(selector)?.addEventListener(type, listener);
        }

        function start() {
            if (!root || !target || typeof target.addEventListener !== 'function') return false;
            target.addEventListener('semyra:host-ready', activate);
            target.addEventListener('semyra:iptv-result', onResult);
            target.addEventListener('semyra:iptv-media-state', onMediaState);

            bind('[data-iptv-settings-open]', 'click', openSettings);
            bind('[data-iptv-onboarding-open]', 'click', openSettings);
            bind('[data-iptv-settings-close]', 'click', function () { closeSettings(false); });
            bind('[data-iptv-settings]', 'cancel', function (event) {
                if (!settingsOperation) return;
                event.preventDefault();
            });
            bind('[data-iptv-settings]', 'close', function () {
                element('[data-iptv-settings-open]')?.setAttribute('aria-expanded', 'false');
            });
            bind('[data-iptv-url-form]', 'submit', function (event) {
                event.preventDefault();
                const form = event.currentTarget;
                const button = form.querySelector ? form.querySelector('button[type="submit"]') : null;
                const name = form.elements.name.value.trim();
                const location = form.elements.location.value.trim();
                if (!name || !location) {
                    feedback('Informe o nome e a URL da fonte.', true);
                    return;
                }
                if (!beginSettingsOperation('adding-url', button)) return;
                operationRequest('add-url', {name, location});
                form.elements.location.value = '';
            });
            bind('[data-iptv-pick-file]', 'click', function (event) {
                if (!beginSettingsOperation('adding-file', event.currentTarget)) return;
                operationRequest('pick-file', {});
            });
            bind('[data-iptv-source-select]', 'change', function (event) { selectSource(Number(event.currentTarget.value)); });
            bind('[data-iptv-source-list]', 'click', function (event) {
                if (settingsOperation) return;
                const button = event.target.closest('[data-iptv-action]');
                const card = button && button.closest('[data-source-id]');
                if (!button || !card) return;
                const sourceId = Number(card.dataset.sourceId);
                if (button.dataset.iptvAction === 'select') {
                    if (!beginSettingsOperation('selecting-source', button, sourceId)) return;
                    loadSourceForOperation(sourceId, true, '');
                }
                if (button.dataset.iptvAction === 'refresh') {
                    if (!beginSettingsOperation('refreshing-source', button, sourceId)) return;
                    operationRequest('refresh', {sourceId});
                }
                if (button.dataset.iptvAction === 'remove'
                    && target.confirm('Remover esta fonte IPTV deste dispositivo?\n\nO catálogo local associado também será removido.')) {
                    if (!beginSettingsOperation('removing-source', button, sourceId)) return;
                    operationRequest('remove', {sourceId});
                }
            });
            bind('[data-iptv-channel-list]', 'click', function (event) {
                const card = event.target.closest('[data-iptv-media-start]');
                if (!card) return;
                const name = card.querySelector('.iptv-channel-copy strong');
                startChannel(Number(card.dataset.iptvMediaStart), name ? name.textContent : '');
            });
            bind('[data-iptv-media-stop]', 'click', stopPlayback);
            bind('[data-iptv-retry]', 'click', function () { if (currentChannelId) startChannel(currentChannelId, currentChannelName); });
            bind('[data-iptv-choose-channel]', 'click', function () { element('[data-iptv-search-form] input[name="query"]')?.focus(); });
            bind('[data-iptv-autoplay]', 'click', function () { if (player) player.play(); });
            bind('[data-iptv-mute]', 'click', function () { updateAudioUi(ensurePlayer().toggleMuted()); showControls(); });
            bind('[data-iptv-volume]', 'input', function (event) { volumeAdjusting = true; updateAudioUi(ensurePlayer().setVolume(Number(event.currentTarget.value) / 100)); showControls(); });
            bind('[data-iptv-volume]', 'change', function () { volumeAdjusting = false; showControls(); });
            bind('[data-iptv-fullscreen]', 'click', toggleFullscreen);
            bind('[data-iptv-search-form]', 'submit', function (event) { event.preventDefault(); offset = 0; search('Buscando canais…'); });
            bind('[data-iptv-search-form] select[name="group"]', 'change', function () { offset = 0; search('Aplicando filtro…'); });
            bind('[data-iptv-previous]', 'click', function () { offset = Math.max(0, offset - limit); search('Carregando página anterior…'); });
            bind('[data-iptv-next]', 'click', function () { offset += limit; search('Carregando próxima página…'); });

            const stage = element('[data-iptv-player-stage]');
            ['mousemove', 'pointerdown', 'keydown', 'focusin', 'focusout'].forEach(function (type) { stage?.addEventListener(type, showControls); });
            document.addEventListener?.('fullscreenchange', updateFullscreenUi);
            target.addEventListener('pagehide', leavePage);
            document.querySelector('form.account-logout[action="/logout"]')?.addEventListener('submit', destroyPlayer);

            fallbackTimer = target.setTimeout(function () {
                if (activated) return;
                const notice = element('[data-iptv-browser-notice]');
                const panel = element('[data-iptv-desktop-panel]');
                if (notice) notice.hidden = false;
                if (panel) panel.hidden = true;
            }, 1200);

            updateAudioUi({volume: .8, muted: false});
            const snapshot = target.SemyraDesktopBridge && target.SemyraDesktopBridge.getHostSnapshot();
            if (snapshot && snapshot.capabilities.includes('iptv.sources')) activate();
            return true;
        }

        return Object.freeze({start});
    }

    return Object.freeze({
        createIptvCatalog,
        mediaStatusLabel,
        validPlaybackUrl,
        localViewAvailable,
        channelMonogram,
        shouldRenderRemoteLogo,
        createHlsPlaybackController,
    });
}));
