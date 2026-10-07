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

    function mediaStatusLabel(state, errorCode) {
        if (state === 'idle') return 'Nenhum teste em andamento.';
        if (state === 'preparing') return 'Preparando canal...';
        if (state === 'streaming') return 'Canal conectado.';
        if (state === 'reconnecting') return 'Reconectando...';
        if (errorCode === 'media_runtime_unavailable') return 'Runtime de mídia indisponível.';
        return 'Não foi possível abrir o canal.';
    }

    function createIptvCatalog(target) {
        const document = target && target.document;
        const root = document && document.querySelector('[data-iptv-catalog]');
        let sources = [];
        let selectedSourceId = null;
        let offset = 0;
        let mediaAvailable = false;
        const limit = 50;

        function element(selector) {
            return root && root.querySelector(selector);
        }

        function request(action, detail) {
            target.dispatchEvent(new target.CustomEvent('semyra:iptv-request', {
                detail: Object.assign({action}, detail || {}),
            }));
        }

        function text(tag, className, value) {
            const node = document.createElement(tag);
            node.className = className;
            node.textContent = value;
            return node;
        }

        function feedback(message, error) {
            const node = element('[data-iptv-feedback]');
            if (node) {
                node.textContent = message || '';
                node.dataset.state = error ? 'error' : 'ok';
            }
        }

        function renderSources() {
            const list = element('[data-iptv-source-list]');
            if (!list) return;
            list.replaceChildren();
            if (sources.length === 0) {
                list.appendChild(text('p', 'iptv-empty', 'Nenhuma fonte adicionada.'));
                selectedSourceId = null;
                renderChannels([], false);
                return;
            }
            if (!sources.some(function (source) { return source.id === selectedSourceId; })) {
                selectedSourceId = sources[0].id;
            }
            sources.forEach(function (source) {
                const card = document.createElement('article');
                card.className = 'iptv-source-card' + (source.id === selectedSourceId ? ' is-selected' : '');
                card.dataset.sourceId = String(source.id);
                card.appendChild(text('h3', '', source.name));
                const type = source.type === 'm3u_file' ? 'Arquivo local' : 'URL';
                card.appendChild(text('p', 'iptv-source-meta', type + ' · ' + source.channelCount + ' canais · ' + source.lastRefreshStatus));
                if (source.lastRefreshError) card.appendChild(text('p', 'iptv-source-meta', source.lastRefreshError));
                const actions = document.createElement('div');
                actions.className = 'iptv-source-actions';
                [['select', 'Abrir'], ['refresh', 'Atualizar'], ['remove', 'Remover']].forEach(function (definition) {
                    const button = text('button', definition[0] === 'select' ? '' : 'button-secondary', definition[1]);
                    button.type = 'button';
                    button.dataset.iptvAction = definition[0];
                    actions.appendChild(button);
                });
                card.appendChild(actions);
                list.appendChild(card);
            });
        }

        function renderGroups(groups) {
            const select = element('[data-iptv-search-form] select[name="group"]');
            if (!select) return;
            const selected = select.value;
            select.replaceChildren();
            const all = document.createElement('option');
            all.value = '';
            all.textContent = 'Todos os grupos';
            select.appendChild(all);
            groups.forEach(function (group) {
                const option = document.createElement('option');
                option.value = group;
                option.textContent = group;
                select.appendChild(option);
            });
            select.value = groups.includes(selected) ? selected : '';
        }

        function renderChannels(channels, hasMore) {
            const list = element('[data-iptv-channel-list]');
            if (!list) return;
            list.replaceChildren();
            if (channels.length === 0) {
                list.appendChild(text('p', 'iptv-empty', selectedSourceId ? 'Nenhum canal encontrado.' : 'Selecione uma fonte.'));
            } else {
                channels.forEach(function (channel) {
                    const card = document.createElement('article');
                    card.className = 'iptv-channel-card';
                    card.appendChild(text('h3', '', channel.name));
                    card.appendChild(text('p', 'iptv-channel-meta', channel.groupName || 'Sem grupo'));
                    const testButton = text('button', 'button-secondary', 'Testar canal');
                    testButton.type = 'button';
                    testButton.dataset.iptvMediaStart = String(channel.id);
                    testButton.disabled = !mediaAvailable;
                    card.appendChild(testButton);
                    list.appendChild(card);
                });
            }
            const previous = element('[data-iptv-previous]');
            const next = element('[data-iptv-next]');
            if (previous) previous.disabled = offset === 0;
            if (next) next.disabled = !hasMore;
        }

        function search() {
            if (!selectedSourceId) return;
            const form = element('[data-iptv-search-form]');
            request('search', {
                sourceId: selectedSourceId,
                query: form.elements.query.value.trim(),
                group: form.elements.group.value,
                offset,
                limit,
            });
        }

        function selectSource(sourceId) {
            selectedSourceId = sourceId;
            offset = 0;
            renderSources();
            request('groups', {sourceId});
            search();
        }

        function onResult(event) {
            const detail = event && event.detail;
            if (!detail || typeof detail.action !== 'string') return;
            if (!detail.ok) {
                feedback(detail.error || 'Operação indisponível.', true);
                request('list');
                return;
            }
            if (detail.action === 'list') {
                sources = detail.sources || [];
                renderSources();
                if (selectedSourceId) selectSource(selectedSourceId);
            } else if (detail.action === 'add-url' || detail.action === 'pick-file') {
                if (detail.cancelled) return;
                feedback('Fonte adicionada.', false);
                request('list');
            } else if (detail.action === 'remove') {
                feedback(detail.removed ? 'Fonte removida.' : 'Fonte não encontrada.', !detail.removed);
                request('list');
            } else if (detail.action === 'refresh') {
                feedback('Catálogo atualizado.', false);
                request('list');
            } else if (detail.action === 'groups') {
                renderGroups(detail.groups || []);
            } else if (detail.action === 'search') {
                offset = detail.offset;
                renderChannels(detail.channels || [], detail.hasMore);
            } else if (detail.action === 'media-start' || detail.action === 'media-stop' || detail.action === 'media-status') {
                renderMediaState(detail);
            }
        }

        function renderMediaState(detail) {
            const status = element('[data-iptv-media-status]');
            const stop = element('[data-iptv-media-stop]');
            if (!status) return;
            status.textContent = mediaStatusLabel(detail.state, detail.errorCode);
            status.dataset.state = detail.state || 'failed';
            if (stop) stop.disabled = !['preparing', 'streaming', 'reconnecting'].includes(detail.state);
        }

        function onMediaState(event) {
            if (event && event.detail) renderMediaState(event.detail);
        }

        function activate(event) {
            const snapshot = event && event.detail
                ? event.detail
                : target.SemyraDesktopBridge && target.SemyraDesktopBridge.getHostSnapshot();
            mediaAvailable = Boolean(snapshot && snapshot.capabilities.includes('iptv.play'));
            const notice = element('[data-iptv-browser-notice]');
            const panel = element('[data-iptv-desktop-panel]');
            if (notice) notice.hidden = true;
            if (panel) panel.hidden = false;
            renderMediaState({state: mediaAvailable ? 'idle' : 'failed', errorCode: mediaAvailable ? null : 'media_runtime_unavailable'});
            request('list');
            if (mediaAvailable) request('media-status');
        }

        function start() {
            if (!root || !target || typeof target.addEventListener !== 'function') return false;
            target.addEventListener('semyra:host-ready', activate);
            target.addEventListener('semyra:iptv-result', onResult);
            target.addEventListener('semyra:iptv-media-state', onMediaState);
            const snapshot = target.SemyraDesktopBridge && target.SemyraDesktopBridge.getHostSnapshot();
            if (snapshot && snapshot.capabilities.includes('iptv.sources')) activate();

            element('[data-iptv-url-form]').addEventListener('submit', function (event) {
                event.preventDefault();
                const form = event.currentTarget;
                request('add-url', {name: form.elements.name.value.trim(), location: form.elements.location.value.trim()});
                form.elements.location.value = '';
            });
            element('[data-iptv-pick-file]').addEventListener('click', function () { request('pick-file'); });
            element('[data-iptv-source-list]').addEventListener('click', function (event) {
                const button = event.target.closest('[data-iptv-action]');
                const card = button && button.closest('[data-source-id]');
                if (!button || !card) return;
                const sourceId = Number(card.dataset.sourceId);
                if (button.dataset.iptvAction === 'select') selectSource(sourceId);
                if (button.dataset.iptvAction === 'refresh') request('refresh', {sourceId});
                if (button.dataset.iptvAction === 'remove' && target.confirm('Remover esta fonte e seu catálogo local?')) request('remove', {sourceId});
            });
            element('[data-iptv-channel-list]').addEventListener('click', function (event) {
                const button = event.target.closest('[data-iptv-media-start]');
                if (!button || !mediaAvailable) return;
                request('media-start', {channelId: Number(button.dataset.iptvMediaStart)});
            });
            element('[data-iptv-media-stop]').addEventListener('click', function () { request('media-stop'); });
            element('[data-iptv-search-form]').addEventListener('submit', function (event) { event.preventDefault(); offset = 0; search(); });
            element('[data-iptv-previous]').addEventListener('click', function () { offset = Math.max(0, offset - limit); search(); });
            element('[data-iptv-next]').addEventListener('click', function () { offset += limit; search(); });
            return true;
        }

        return Object.freeze({start});
    }

    return Object.freeze({createIptvCatalog, mediaStatusLabel});
}));
