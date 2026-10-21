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

    const VISUAL_STATES = ['idle', 'preparing', 'loading', 'streaming', 'playing', 'paused', 'buffering', 'reconnecting', 'ended', 'failed'];
    const ACTIVE_STATES = ['preparing', 'loading', 'streaming', 'playing', 'paused', 'buffering', 'reconnecting'];
    const CONTROLS_AUTO_HIDE_MS = 3000;
    const SETTINGS_STATES = Object.freeze({
        'syncing-vod': ['Sincronizando filmes e séries…', 'Buscando o catálogo Xtream sob demanda.'],
        'adding-xtream': ['Conectando ao Xtreamâ€¦', 'Autenticando e importando os canais ao vivo.'],
        'adding-file': ['Importando sua IPTV…', 'Lendo o arquivo e carregando os canais.'],
        'adding-url': ['Carregando sua IPTV…', 'Conectando à fonte e importando os canais.'],
        'selecting-source': ['Carregando canais…', 'Preparando esta fonte para você.'],
        'refreshing-source': ['Atualizando catálogo…', 'Buscando a versão mais recente dos canais.'],
        'removing-source': ['Removendo fonte…', 'Apagando o catálogo local deste dispositivo.'],
    });

    function mediaStatusLabel(state, errorCode) {
        if (state === 'loading') return 'Carregando no MPV...';
        if (state === 'playing') return 'Reproduzindo no MPV';
        if (state === 'paused') return 'Pausado';
        if (state === 'buffering') return 'Armazenando dados...';
        if (state === 'ended') return 'Reproducao concluida';
        if (state === 'idle') return 'Escolha um canal';
        if (state === 'preparing') return 'Preparando canal…';
        if (state === 'streaming') return 'Reproduzindo agora';
        if (state === 'reconnecting') return 'Reconectando…';
        if (['media_runtime_unavailable', 'mpv_runtime_unavailable'].includes(errorCode)) return 'Player local indisponível neste dispositivo.';
        return 'Não foi possível reproduzir este conteúdo.';
    }

    function localViewAvailable(snapshot) {
        return Boolean(snapshot && Array.isArray(snapshot.capabilities)
            && snapshot.capabilities.includes('iptv.local-view'));
    }

    function formatMediaTime(value) {
        const seconds = Math.max(0, Math.floor(Number(value) || 0));
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainder = seconds % 60;
        return (hours ? String(hours) + ':' + String(minutes).padStart(2, '0') : String(minutes))
            + ':' + String(remainder).padStart(2, '0');
    }

    function syncRangeVisual(control, value, minimum = 0, maximum = 100) {
        if (!control) return 0;
        const min = Number.isFinite(Number(minimum)) ? Number(minimum) : 0;
        const max = Number.isFinite(Number(maximum)) && Number(maximum) > min ? Number(maximum) : 100;
        const normalized = Math.min(max, Math.max(min, Number(value) || 0));
        const progress = ((normalized - min) / (max - min)) * 100;
        control.value = String(Math.round(normalized));
        control.dataset.rangeProgress = String(Math.round(progress));
        control.style?.setProperty?.('--iptv-range-progress', progress + '%');
        return normalized;
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

    function groupEpisodesBySeason(episodes) {
        const seasons = new Map();
        (Array.isArray(episodes) ? episodes : []).forEach(function (episode) {
            if (!episode || !Number.isSafeInteger(episode.seasonNumber)) return;
            if (!seasons.has(episode.seasonNumber)) seasons.set(episode.seasonNumber, []);
            seasons.get(episode.seasonNumber).push(episode);
        });
        return Array.from(seasons.entries()).sort(function (a, b) { return a[0] - b[0]; });
    }

    function displayEpisodeDuration(value) {
        if (typeof value !== 'string' || !value.trim()) return '';
        const parts = value.trim().split(':');
        if (parts.every(function (part) { return /^\d+$/.test(part); })
            && parts.every(function (part) { return Number(part) === 0; })) return '';
        return value.trim();
    }

    function createIptvCatalog(target) {
        const document = target && target.document;
        const root = document && document.querySelector('[data-iptv-catalog]');
        let sources = [];
        let selectedSourceId = null;
        let offset = 0;
        let mediaAvailable = false;
        let currentChannelId = null;
        let currentChannelName = '';
        let currentVodKind = null;
        let currentVodMediaId = null;
        let mediaState = 'idle';
        let hideTimer = null;
        let controlsVisible = true;
        let lastUserActivity = 0;
        let volumeAdjusting = false;
        let activated = false;
        let fallbackTimer = null;
        let requestSequence = 0;
        let listRequestToken = null;
        let groupsRequestToken = null;
        let searchRequestToken = null;
        let activeView = 'all';
        let primaryView = 'live';
        const vodState = {
            movies: {offset: 0, categories: [], items: [], hasMore: false, requestToken: null, categoryToken: null, selected: null},
            series: {offset: 0, categories: [], items: [], hasMore: false, requestToken: null, categoryToken: null, detailsToken: null, selected: null, episodes: [], detailsStale: false, continueContext: null},
        };
        const continueState = {movies: [], series: [], requestToken: null};
        const continueDismissRequests = new Map();
        let viewRequestAction = 'search';
        const favoriteRequests = new Map();
        let settingsOperation = null;
        let navigationStopRequested = false;
        let nativeSessionId = null;
        let nativeMediaKind = null;
        let nativeSessionNeedsAutoHide = false;
        let nativePaused = false;
        let nativeMuted = false;
        let nativeVolume = 100;
        let nativeFullscreen = false;
        let nativeScrubbing = false;
        let nativeRecentSession = null;
        let fullscreenScrollPosition = null;
        let renderedChannels = [];
        let renderedHasMore = false;
        const recentRemoveRequests = new Map();
        let recentsClearRequest = null;
        let recentsConfirming = false;
        let geometryTimer = null;
        let geometryObserver = null;
        let intersectionObserver = null;
        let stageIntersecting = true;
        const groupsBySource = new Map();
        const limit = 50;

        function currentSource() { return sources.find(function (source) { return source.id === selectedSourceId; }) || null; }

        function renderPrimaryNavigation() {
            const xtream = currentSource()?.type === 'xtream';
            if (!xtream && primaryView !== 'live') primaryView = 'live';
            root.querySelectorAll?.('[data-iptv-primary]').forEach(function (tab) {
                const vod = tab.dataset.iptvPrimary !== 'live';
                tab.hidden = vod && !xtream;
                tab.disabled = vod && !xtream;
                const selected = tab.dataset.iptvPrimary === primaryView;
                tab.classList.toggle('is-active', selected);
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.setAttribute('tabindex', selected ? '0' : '-1');
            });
            root.querySelectorAll?.('[data-iptv-primary-panel]').forEach(function (panel) {
                panel.hidden = panel.dataset.iptvPrimaryPanel !== primaryView;
            });
        }

        function setPrimaryView(view, focus) {
            if (!['live', 'movies', 'series'].includes(view) || (view !== 'live' && currentSource()?.type !== 'xtream')) return;
            primaryView = view;
            renderPrimaryNavigation();
            if (view !== 'live') {
                loadVod(view, true);
                loadContinueWatching();
            }
            if (focus) element('[data-iptv-primary="' + view + '"]')?.focus();
        }

        function vodAction(view) { return view === 'movies' ? 'vod-movies-search' : 'vod-series-search'; }
        function vodMediaType(view) { return view === 'movies' ? 'movie' : 'series'; }

        function renderVodCategories(view) {
            const select = element('[data-iptv-vod-form="' + view + '"] select[name="category"]');
            if (!select) return;
            const selected = select.value;
            const fragment = document.createDocumentFragment();
            const all = node('option', '', 'Todas as categorias'); all.value = ''; fragment.appendChild(all);
            vodState[view].categories.forEach(function (category) {
                const option = node('option', '', category.name); option.value = category.externalCategoryId; fragment.appendChild(option);
            });
            select.replaceChildren(fragment); select.value = vodState[view].categories.some(function (item) { return item.externalCategoryId === selected; }) ? selected : '';
        }

        function vodEmpty(view, message) {
            const empty = node('div', 'iptv-empty-state iptv-vod-empty');
            empty.appendChild(node('span', 'iptv-channel-monogram', view === 'movies' ? 'FL' : 'SR'));
            empty.appendChild(node('p', '', message));
            if (currentSource()?.vodSyncState !== 'ready') {
                const button = node('button', 'iptv-button-quiet', 'Abrir configurações IPTV'); button.type = 'button'; button.dataset.iptvVodSettings = 'true'; empty.appendChild(button);
            }
            return empty;
        }

        function safePoster(item, alt) {
            const visual = node('span', 'iptv-vod-poster');
            if (typeof item.posterUrl === 'string' && /^https?:\/\//i.test(item.posterUrl)) {
                const image = node('img', ''); image.src = item.posterUrl; image.alt = alt; image.loading = 'lazy'; image.referrerPolicy = 'no-referrer';
                image.addEventListener('error', function () { image.remove(); visual.appendChild(node('span', 'iptv-vod-poster-fallback', channelMonogram(item.name))); });
                visual.appendChild(image);
            } else visual.appendChild(node('span', 'iptv-vod-poster-fallback', channelMonogram(item.name)));
            return visual;
        }

        function progressBar(percent, label) {
            const wrap = node('span', 'iptv-vod-progress');
            const bar = node('span', 'iptv-vod-progress-bar');
            const value = Math.min(100, Math.max(0, Number(percent) || 0));
            bar.style.setProperty('--iptv-vod-progress', value + '%');
            bar.setAttribute('role', 'progressbar');
            bar.setAttribute('aria-valuemin', '0');
            bar.setAttribute('aria-valuemax', '100');
            bar.setAttribute('aria-valuenow', String(Math.round(value)));
            bar.setAttribute('aria-label', label || 'Progresso assistido');
            wrap.appendChild(bar);
            return wrap;
        }

        function renderContinueWatching(view) {
            const section = element('[data-iptv-vod-continue-section="' + view + '"]');
            const list = element('[data-iptv-vod-continue-list="' + view + '"]');
            if (!section || !list) return;
            const items = continueState[view];
            section.hidden = items.length === 0;
            list.replaceChildren();
            items.forEach(function (item) {
                const card = node('article', 'iptv-vod-continue-card');
                const primary = node('button', 'iptv-vod-continue-primary');
                primary.type = 'button';
                if (view === 'movies') {
                    primary.dataset.iptvVodPlay = 'movie';
                    primary.dataset.mediaId = String(item.id);
                } else {
                    primary.dataset.iptvContinueSeries = String(item.seriesId);
                    primary.dataset.episodeId = String(item.episodeId);
                    primary.dataset.seasonNumber = String(item.seasonNumber);
                }
                primary.dataset.mediaName = item.name;
                primary.appendChild(safePoster(item, 'Capa de ' + item.name));
                const copy = node('span', 'iptv-vod-card-copy');
                copy.appendChild(node('strong', '', item.name));
                if (view === 'series') {
                    const number = 'S' + String(item.seasonNumber).padStart(2, '0') + (item.episodeNumber === null ? '' : 'E' + String(item.episodeNumber).padStart(2, '0'));
                    copy.appendChild(node('span', '', (item.completedPrevious ? 'Próximo: ' : '') + number + (item.episodeTitle ? ' · ' + item.episodeTitle : '')));
                } else copy.appendChild(node('span', '', 'Continuar em ' + formatMediaTime(item.position)));
                copy.appendChild(progressBar(item.progressPercent, Math.round(item.progressPercent) + '% assistido'));
                primary.appendChild(copy);
                card.appendChild(primary);
                const remove = node('button', 'iptv-vod-continue-remove');
                remove.type = 'button';
                remove.dataset.iptvContinueDismiss = view;
                remove.dataset.mediaId = String(view === 'movies' ? item.id : item.seriesId);
                remove.setAttribute('aria-label', 'Remover de Continuar assistindo');
                remove.title = 'Remover de Continuar assistindo';
                remove.textContent = '×';
                card.appendChild(remove);
                list.appendChild(card);
            });
        }

        function loadContinueWatching() {
            if (!selectedSourceId || currentSource()?.type !== 'xtream') return;
            continueState.requestToken = nextRequestToken('vod-continue');
            request('vod-continue', {sourceId: selectedSourceId}, continueState.requestToken);
        }

        function renderVod(view) {
            const state = vodState[view]; const list = element('[data-iptv-vod-list="' + view + '"]'); if (!list) return;
            const fragment = document.createDocumentFragment();
            if (state.items.length === 0) {
                const source = currentSource();
                const message = source?.vodSyncState !== 'ready' ? 'Sincronize filmes e séries nas configurações para preencher este catálogo.' : 'Nenhum conteúdo encontrado.';
                fragment.appendChild(vodEmpty(view, message));
            } else state.items.forEach(function (item) {
                const card = node('article', 'iptv-vod-card');
                const button = node('button', 'iptv-vod-card-button'); button.type = 'button'; button.dataset.iptvVodOpen = view; button.dataset.mediaId = String(item.id); button.setAttribute('aria-label', 'Ver detalhes de ' + item.name); button.title = item.name;
                button.appendChild(safePoster(item, 'Capa de ' + item.name));
                const copy = node('span', 'iptv-vod-card-copy'); copy.appendChild(node('strong', '', item.name)); copy.appendChild(node('span', '', [item.year, item.rating ? '★ ' + item.rating : null, item.genre].filter(Boolean).join(' · ') || (view === 'movies' ? 'Filme' : 'Série'))); button.appendChild(copy); card.appendChild(button); fragment.appendChild(card);
            });
            list.replaceChildren(fragment); list.setAttribute('aria-busy', 'false');
            const count = element('[data-iptv-vod-count="' + view + '"]'); if (count) count.textContent = state.items.length ? state.items.length + (state.hasMore ? '+ nesta página' : ' nesta página') : '';
            const page = element('[data-iptv-vod-page="' + view + '"]'); if (page) page.textContent = 'Página ' + (Math.floor(state.offset / limit) + 1);
            const previous = element('[data-iptv-vod-previous="' + view + '"]'); if (previous) previous.disabled = state.offset === 0;
            const next = element('[data-iptv-vod-next="' + view + '"]'); if (next) next.disabled = !state.hasMore;
        }

        function loadVod(view, includeCategories) {
            if (!selectedSourceId || currentSource()?.type !== 'xtream') return;
            const state = vodState[view]; const form = element('[data-iptv-vod-form="' + view + '"]');
            const list = element('[data-iptv-vod-list="' + view + '"]'); if (list) { list.setAttribute('aria-busy', 'true'); list.replaceChildren(node('span', 'iptv-skeleton')); }
            if (includeCategories && state.categories.length === 0) { state.categoryToken = nextRequestToken('vod-categories'); request('vod-categories', {sourceId: selectedSourceId, mediaType: vodMediaType(view)}, state.categoryToken); }
            state.requestToken = nextRequestToken(vodAction(view));
            request(vodAction(view), {sourceId: selectedSourceId, query: form?.elements.query.value.trim() || '', category: form?.elements.category.value || null, offset: state.offset, limit}, state.requestToken);
        }

        function showVodDetail(view, item, continueContext) {
            vodState[view].selected = item;
            if (view === 'series') vodState.series.continueContext = continueContext || null;
            const browser = element('[data-iptv-vod-browser="' + view + '"]'); const detail = element('[data-iptv-vod-detail="' + view + '"]'); if (!detail) return;
            if (browser) browser.hidden = true; detail.hidden = false; detail.replaceChildren();
            const back = node('button', 'iptv-button-quiet iptv-vod-back', '← Voltar ao catálogo'); back.type = 'button'; back.dataset.iptvVodBack = view; detail.appendChild(back);
            const layout = node('div', 'iptv-vod-detail-layout'); layout.appendChild(safePoster(item, 'Capa de ' + item.name));
            const copy = node('div', 'iptv-vod-detail-copy'); copy.appendChild(node('p', 'eyebrow', view === 'movies' ? 'Filme' : 'Série')); copy.appendChild(node('h2', '', item.name));
            const meta = [item.year, item.rating ? '★ ' + item.rating : null, item.genre].filter(Boolean); if (meta.length) copy.appendChild(node('p', 'iptv-vod-meta', meta.join(' · ')));
            if (item.plot) copy.appendChild(node('p', 'iptv-vod-plot', item.plot));
            if (view === 'movies') {
                const progress = continueState.movies.find(function (entry) { return entry.id === item.id; });
                const play = node('button', 'iptv-vod-play', progress ? 'Continuar' : 'Assistir'); play.type = 'button'; play.dataset.iptvVodPlay = 'movie'; play.dataset.mediaId = String(item.id); play.dataset.mediaName = item.name; copy.appendChild(play);
                if (progress) copy.appendChild(node('p', 'iptv-vod-resume-copy', 'Continuar em ' + formatMediaTime(progress.position)));
            }
            else { const episodes = node('div', 'iptv-series-episodes', 'Carregando episódios…'); episodes.dataset.iptvSeriesEpisodes = 'true'; copy.appendChild(episodes); const state = vodState.series; state.episodes = []; state.detailsStale = false; state.detailsToken = nextRequestToken('vod-series-details'); request('vod-series-details', {seriesId: item.id, refresh: true}, state.detailsToken); }
            layout.appendChild(copy); detail.appendChild(layout); back.focus?.();
        }

        function populateEpisodeRow(row, episode, continueEpisodeId) {
            const isContinue = !episode.completed && (episode.id === continueEpisodeId || episode.continueHere === true);
            row.className = 'iptv-episode' + (episode.completed ? ' is-watched' : episode.position >= 5 ? ' is-in-progress' : '') + (isContinue ? ' is-continue' : '');
            row.dataset.iptvEpisodeId = String(episode.id);
            const copy = node('div', 'iptv-episode-copy');
            copy.appendChild(node('strong', '', (episode.episodeNumber ? 'E' + String(episode.episodeNumber).padStart(2, '0') + ' · ' : '') + episode.name));
            const duration = displayEpisodeDuration(episode.duration);
            if (duration) copy.appendChild(node('span', 'iptv-episode-duration', duration));
            const progress = node('span', 'iptv-episode-progress'); progress.dataset.iptvEpisodeProgress = 'true';
            if (episode.completed) progress.appendChild(node('span', 'iptv-episode-state', '✓ Assistido'));
            else if (episode.position >= 5) {
                progress.appendChild(node('span', 'iptv-episode-state', Math.round(episode.progressPercent) + '% assistido'));
                progress.appendChild(progressBar(episode.progressPercent, Math.round(episode.progressPercent) + '% assistido'));
            }
            if (isContinue) progress.appendChild(node('span', 'iptv-episode-continue', 'Continuar daqui'));
            copy.appendChild(progress);
            if (episode.plot) copy.appendChild(node('p', '', episode.plot));
            const play = node('button', '', (episode.position >= 5 && !episode.completed) || isContinue ? 'Continuar' : 'Assistir');
            play.type = 'button'; play.dataset.iptvVodPlay = 'episode'; play.dataset.mediaId = String(episode.id); play.dataset.mediaName = episode.name;
            row.replaceChildren(copy, play);
            return row;
        }

        function renderSeriesEpisodes(episodes, stale) {
            const container = element('[data-iptv-series-episodes]'); if (!container) return; container.replaceChildren();
            vodState.series.episodes = episodes.slice(); vodState.series.detailsStale = stale;
            if (stale) container.appendChild(node('p', 'iptv-vod-warning', 'Não foi possível atualizar agora. Exibindo os episódios salvos.'));
            if (!episodes.length) { container.appendChild(node('p', '', 'Nenhum episódio disponível.')); return; }
            const grouped = groupEpisodesBySeason(episodes);
            const context = vodState.series.continueContext;
            const selectedSeason = context && grouped.some(function (entry) { return entry[0] === context.seasonNumber; })
                ? context.seasonNumber : grouped[0][0];
            const tabs = node('div', 'iptv-season-tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Temporadas'); container.appendChild(tabs);
            grouped.forEach(function (entry) {
                const season = entry[0]; const seasonEpisodes = entry[1];
                const label = season === 0 ? 'Especiais' : 'Temporada ' + season;
                const active = season === selectedSeason;
                const tab = node('button', active ? 'is-active' : '', label); tab.type = 'button'; tab.dataset.iptvSeasonTab = String(season); tab.setAttribute('role', 'tab'); tab.setAttribute('aria-selected', active ? 'true' : 'false'); tabs.appendChild(tab);
                const section = node('section', 'iptv-season'); section.dataset.iptvSeason = String(season); section.hidden = !active; section.appendChild(node('h3', '', label));
                seasonEpisodes.forEach(function (episode) {
                    section.appendChild(populateEpisodeRow(node('div', ''), episode, context?.episodeId));
                }); container.appendChild(section);
            });
            if (context) container.querySelector?.('[data-iptv-episode-id="' + context.episodeId + '"]')?.scrollIntoView?.({block: 'nearest', behavior: 'smooth'});
        }

        function onVodProgressChanged(event) {
            const detail = event && event.detail;
            if (!detail || detail.sourceId !== selectedSourceId || detail.mediaType !== 'episode'
                || detail.seriesId !== vodState.series.selected?.id || primaryView !== 'series'
                || (nativeSessionId && detail.sessionId !== nativeSessionId)) return;
            const index = vodState.series.episodes.findIndex(function (episode) { return episode.id === detail.mediaId; });
            if (index < 0) return;
            const updated = Object.assign({}, vodState.series.episodes[index], {
                position: detail.positionSeconds,
                progressPercent: detail.progressPercent,
                completed: detail.completed === true,
            });
            vodState.series.episodes[index] = updated;
            const row = element('[data-iptv-series-episodes]')?.querySelector?.('[data-iptv-episode-id="' + detail.mediaId + '"]');
            if (row) populateEpisodeRow(row, updated, vodState.series.continueContext?.episodeId);
            const continued = continueState.series.find(function (item) { return item.episodeId === detail.mediaId; });
            if (continued) {
                if (detail.completed === true) {
                    loadContinueWatching();
                    return;
                }
                continued.position = detail.positionSeconds;
                continued.duration = detail.durationSeconds;
                continued.progressPercent = detail.progressPercent;
                renderContinueWatching('series');
            }
        }

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

        function reportControlsVisibility(hidden) {
            target.dispatchEvent(new target.CustomEvent('semyra:player-controls-visibility', {
                detail: Object.freeze({hidden: hidden === true}),
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
                const type = source.type === 'xtream' ? 'Xtream' : (source.type === 'm3u_file' ? 'Arquivo local' : 'Lista por URL');
                copy.appendChild(node('p', 'iptv-source-meta', type + ' · ' + source.channelCount.toLocaleString('pt-BR') + ' canais · ' + sourceStatusLabel(source.lastRefreshStatus)));
                if (source.type === 'xtream') {
                    copy.appendChild(node('p', 'iptv-source-meta', source.movieCount.toLocaleString('pt-BR') + ' filmes · ' + source.seriesCount.toLocaleString('pt-BR') + ' séries · VOD ' + sourceStatusLabel(source.vodSyncState)));
                    if (source.vodSyncError) copy.appendChild(node('p', 'iptv-source-error', source.vodSyncError));
                }
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
                if (source.type === 'xtream') {
                    const vod = node('button', '', 'Sincronizar filmes e séries');
                    vod.type = 'button';
                    vod.dataset.iptvAction = 'vod-refresh';
                    actions.appendChild(vod);
                }
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

        function recentsFeedback(message, error) {
            const output = element('[data-iptv-recents-feedback]');
            if (!output) return;
            output.textContent = message || '';
            output.dataset.state = error ? 'error' : 'ok';
        }

        function renderRecentsActions() {
            const actions = element('[data-iptv-recents-actions]');
            const clear = element('[data-iptv-recents-clear]');
            const confirmation = element('[data-iptv-recents-confirm]');
            const confirmClear = element('[data-iptv-recents-confirm-clear]');
            const cancelClear = element('[data-iptv-recents-cancel]');
            const visible = activeView === 'recents' && renderedChannels.length > 0;
            if (actions) actions.hidden = !visible;
            const pending = recentsClearRequest?.sourceId === selectedSourceId;
            if (clear) {
                clear.hidden = recentsConfirming;
                clear.disabled = pending;
                clear.setAttribute('aria-busy', pending ? 'true' : 'false');
            }
            if (confirmation) confirmation.hidden = !recentsConfirming;
            if (confirmClear) confirmClear.disabled = pending;
            if (cancelClear) cancelClear.disabled = pending;
            if (activeView !== 'recents') recentsFeedback('', false);
        }

        function renderActiveView() {
            if (!root || typeof root.querySelectorAll !== 'function') return;
            renderRecentsActions();
            root.querySelectorAll('[data-iptv-view]').forEach(function (tab) {
                const selected = tab.dataset.iptvView === activeView;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.setAttribute('tabindex', selected ? '0' : '-1');
                tab.classList.toggle('is-active', selected);
            });
            const toolbar = element('[data-iptv-search-form]');
            const pagination = element('[data-iptv-pagination]');
            if (toolbar) toolbar.hidden = activeView !== 'all';
            if (pagination) pagination.hidden = activeView === 'recents';
            const panel = element('[data-iptv-view-panel]');
            if (panel) panel.setAttribute('aria-labelledby', 'iptv-view-' + activeView);
            const title = element('[data-iptv-channels-title]');
            if (title) title.textContent = activeView === 'favorites'
                ? 'Seus favoritos'
                : activeView === 'recents' ? 'Assistidos recentemente' : 'O que você quer assistir?';
        }

        function setActiveView(view, focus) {
            if (!['favorites', 'recents', 'all'].includes(view) || view === activeView) return;
            activeView = view;
            recentsConfirming = false;
            offset = 0;
            renderActiveView();
            loadCurrentView('Carregando canais…');
            if (focus) element('[data-iptv-view="' + view + '"]')?.focus();
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
            renderedChannels = [];
            renderedHasMore = false;
            renderRecentsActions();
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
            root.querySelectorAll('[data-iptv-view-start]').forEach(function (card) {
                const active = currentVodKind === null && Number(card.dataset.iptvViewStart) === currentChannelId && ACTIVE_STATES.includes(mediaState);
                const shell = card.closest('.iptv-channel-card') || card;
                shell.classList.toggle('is-active', active);
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
            renderedChannels = Array.isArray(channels) ? channels.slice() : [];
            renderedHasMore = hasMore === true;
            const fragment = document.createDocumentFragment();
            const form = element('[data-iptv-search-form]');
            const query = activeView === 'all' && form ? form.elements.query.value.trim() : '';
            if (channels.length === 0) {
                const message = activeView === 'favorites'
                    ? 'Seus canais favoritos aparecerão aqui. Favorite um canal para encontrá-lo mais rápido.'
                    : activeView === 'recents'
                        ? 'Você ainda não assistiu nenhum conteúdo.'
                        : query ? 'Nenhum canal encontrado.' : 'Esta fonte ainda não possui canais.';
                fragment.appendChild(renderChannelEmpty(message));
            } else {
                channels.forEach(function (channel) {
                    const card = node('article', 'iptv-channel-card');
                    card.dataset.iptvChannelCard = String(channel.id);
                    const play = node('button', 'iptv-channel-play');
                    play.type = 'button';
                    play.dataset.iptvViewStart = String(channel.id);
                    play.disabled = !mediaAvailable || (channel.id === currentChannelId && ACTIVE_STATES.includes(mediaState));
                    play.setAttribute('aria-label', 'Assistir ' + channel.name);
                    play.setAttribute('aria-pressed', 'false');
                    const visual = node('span', 'iptv-channel-visual');
                    visual.appendChild(node('span', 'iptv-channel-monogram', channelMonogram(channel.name)));
                    const copy = node('span', 'iptv-channel-copy');
                    copy.appendChild(node('strong', '', channel.name));
                    copy.appendChild(node('span', '', channel.groupName || 'Sem grupo'));
                    play.appendChild(visual);
                    play.appendChild(copy);
                    card.appendChild(play);
                    const favorite = node('button', 'iptv-favorite-button');
                    favorite.type = 'button';
                    favorite.dataset.iptvFavorite = String(channel.id);
                    favorite.dataset.favorite = channel.isFavorite ? 'true' : 'false';
                    favorite.setAttribute('aria-pressed', channel.isFavorite ? 'true' : 'false');
                    favorite.setAttribute('aria-label', channel.isFavorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos');
                    favorite.title = channel.isFavorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos';
                    const icon = document.createElementNS ? document.createElementNS('http://www.w3.org/2000/svg', 'svg') : node('svg', '');
                    icon.setAttribute('aria-hidden', 'true');
                    const use = document.createElementNS ? document.createElementNS('http://www.w3.org/2000/svg', 'use') : node('use', '');
                    use.setAttribute('href', '#iptv-icon-heart');
                    icon.appendChild(use);
                    favorite.appendChild(icon);
                    card.appendChild(favorite);
                    if (activeView === 'recents') {
                        const removeRecent = node('button', 'iptv-recent-remove');
                        removeRecent.type = 'button';
                        removeRecent.dataset.iptvRecentRemove = 'true';
                        removeRecent.dataset.recentId = String(channel.recentId);
                        removeRecent.setAttribute('aria-label', 'Remover de recentes');
                        removeRecent.title = 'Remover de recentes';
                        const removeIcon = document.createElementNS ? document.createElementNS('http://www.w3.org/2000/svg', 'svg') : node('svg', '');
                        removeIcon.setAttribute('aria-hidden', 'true');
                        const removeUse = document.createElementNS ? document.createElementNS('http://www.w3.org/2000/svg', 'use') : node('use', '');
                        removeUse.setAttribute('href', '#iptv-icon-close');
                        removeIcon.appendChild(removeUse);
                        removeRecent.appendChild(removeIcon);
                        card.appendChild(removeRecent);
                    }
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
            renderRecentsActions();
            updateActiveCards();
        }

        function search(message) {
            if (!selectedSourceId) return;
            const form = element('[data-iptv-search-form]');
            viewRequestAction = 'search';
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

        function loadCurrentView(message) {
            if (!selectedSourceId) return;
            if (activeView === 'all') {
                viewRequestAction = 'search';
                search(message);
                return;
            }
            viewRequestAction = activeView;
            searchRequestToken = nextRequestToken(activeView);
            setCatalogLoading(true, message || 'Carregando canais…');
            request(activeView, {
                sourceId: selectedSourceId,
                offset: activeView === 'favorites' ? offset : 0,
                limit,
            }, searchRequestToken);
        }

        function selectSource(sourceId) {
            if (!sources.some(function (source) { return source.id === sourceId; })) return null;
            selectedSourceId = sourceId;
            recentsConfirming = false;
            offset = 0;
            primaryView = 'live';
            Object.entries(vodState).forEach(function (entry) {
                const view = entry[0]; const state = entry[1];
                state.offset = 0; state.categories = []; state.items = []; state.selected = null; state.requestToken = null; state.categoryToken = null; if ('detailsToken' in state) state.detailsToken = null;
                const browser = element('[data-iptv-vod-browser="' + view + '"]'); const detail = element('[data-iptv-vod-detail="' + view + '"]'); if (browser) browser.hidden = false; if (detail) { detail.hidden = true; detail.replaceChildren(); }
            });
            continueState.movies = [];
            continueState.series = [];
            continueState.requestToken = null;
            renderContinueWatching('movies');
            renderContinueWatching('series');
            renderSources();
            renderPrimaryNavigation();
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
            loadCurrentView('Trocando fonte…');
            return {groups: groupsRequestToken, view: searchRequestToken, viewAction: viewRequestAction};
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
            settingsOperation.tokens.set(tokens.viewAction, tokens.view);
            settingsOperation.pending.add(tokens.viewAction);
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

        function destroyPlayer() {
            if (nativeSessionId || nativeFullscreen || !controlsVisible) reportControlsVisibility(false);
            hideNativePlayerSurface();
            clearHideTimer();
            const stage = element('[data-iptv-player-stage]');
            if (stage) {
                stage.dataset.controlsHidden = 'false';
                stage.dataset.nativePlayer = 'false';
            }
            nativeSessionId = null;
            nativeMediaKind = null;
            nativeSessionNeedsAutoHide = false;
            nativeFullscreen = false;
            nativeScrubbing = false;
            nativeRecentSession = null;
            clearTimeout(geometryTimer);
            geometryTimer = null;
            if (geometryObserver) geometryObserver.disconnect();
            geometryObserver = null;
            if (intersectionObserver) intersectionObserver.disconnect();
            intersectionObserver = null;
            stageIntersecting = true;
            root?.classList?.remove?.('is-native-fullscreen', 'is-native-controls-hidden');
            setDocumentFullscreenLock(false);
            const controls = element('[data-iptv-native-controls]');
            if (controls) controls.hidden = true;
        }

        function hideNativePlayerSurface() {
            if (!nativeSessionId) return;
            request('player-geometry', {
                sessionId: nativeSessionId,
                x: 0,
                y: 0,
                width: 0,
                height: 0,
                viewportWidth: Math.max(1, Number(target.innerWidth) || 1),
                viewportHeight: Math.max(1, Number(target.innerHeight) || 1),
                visible: false,
            });
        }

        function sendNativeControl(command, value) {
            if (!nativeSessionId) return;
            userActivity();
            const detail = {sessionId: nativeSessionId, command};
            if (typeof value === 'number') detail.value = value;
            request('player-control', detail);
        }

        function renderNativeControlModel(state) {
            const playback = element('[data-iptv-native-playback]');
            if (playback) {
                playback.dataset.command = state.paused ? 'play' : 'pause';
                playback.setAttribute('aria-label', state.paused ? 'Reproduzir' : 'Pausar');
                playback.title = state.paused ? 'Reproduzir' : 'Pausar';
                renderNativeIcon(playback, state.paused ? 'play' : 'pause');
            }
            const mute = element('[data-iptv-native-mute]');
            if (mute) {
                mute.setAttribute('aria-label', state.muted ? 'Ativar som' : 'Silenciar');
                mute.title = state.muted ? 'Ativar som' : 'Silenciar';
                renderNativeIcon(mute, state.muted ? 'muted' : 'volume');
            }
            syncRangeVisual(element('[data-iptv-native-volume]'), state.volume);
        }

        function renderNativeIcon(button, name) {
            const current = button.children && button.children[0];
            if (current && current.dataset && current.dataset.playerIcon === name) return;
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
            svg.dataset.playerIcon = name;
            svg.setAttribute('aria-hidden', 'true');
            use.setAttribute('href', '#iptv-icon-' + name);
            svg.appendChild(use);
            button.replaceChildren(svg);
        }

        function reportNativeGeometry() {
            geometryTimer = null;
            if (!nativeSessionId) return;
            const stage = element('[data-iptv-player-stage]');
            if (!stage || typeof stage.getBoundingClientRect !== 'function') return;
            const rect = stage.getBoundingClientRect();
            const viewportWidth = Math.max(1, Number(target.innerWidth) || rect.width || 1);
            const viewportHeight = Math.max(1, Number(target.innerHeight) || rect.height || 1);
            const left = Math.max(0, Math.min(viewportWidth, rect.left));
            const top = Math.max(0, Math.min(viewportHeight, rect.top));
            const rectRight = Number.isFinite(rect.right) ? rect.right : rect.left + rect.width;
            const rectBottom = Number.isFinite(rect.bottom) ? rect.bottom : rect.top + rect.height;
            const right = Math.max(left, Math.min(viewportWidth, rectRight));
            const bottom = Math.max(top, Math.min(viewportHeight, rectBottom));
            const visible = stageIntersecting && right - left >= 1 && bottom - top >= 1;
            request('player-geometry', {
                sessionId: nativeSessionId,
                x: visible ? left : 0,
                y: visible ? top : 0,
                width: visible ? right - left : 0,
                height: visible ? bottom - top : 0,
                viewportWidth,
                viewportHeight,
                visible,
            });
        }

        function scheduleNativeGeometry() {
            if (!nativeSessionId || geometryTimer !== null) return;
            geometryTimer = target.setTimeout(reportNativeGeometry, 50);
        }

        function attachNativePlayer(sessionId) {
            if (typeof sessionId !== 'string' || !/^[a-f0-9]{32}$/.test(sessionId)) return false;
            const changed = nativeSessionId !== sessionId;
            nativeSessionId = sessionId;
            if (changed) nativeSessionNeedsAutoHide = true;
            nativePaused = false;
            const stage = element('[data-iptv-player-stage]');
            if (stage) stage.dataset.nativePlayer = 'true';
            const controls = element('[data-iptv-native-controls]');
            if (controls) controls.hidden = false;
            if (typeof target.ResizeObserver === 'function' && stage) {
                geometryObserver = new target.ResizeObserver(scheduleNativeGeometry);
                geometryObserver.observe(stage);
            }
            if (typeof target.IntersectionObserver === 'function' && stage) {
                intersectionObserver = new target.IntersectionObserver(function (entries) {
                    stageIntersecting = entries.some(function (entry) { return entry.isIntersecting && entry.intersectionRatio > 0; });
                    scheduleNativeGeometry();
                }, {threshold: [0, 0.01, 1]});
                intersectionObserver.observe(stage);
            }
            scheduleNativeGeometry();
            return true;
        }

        function renderMediaState(detail) {
            if (detail.playbackKind === 'native-mpv' && detail.sessionId && nativeSessionId !== detail.sessionId) {
                attachNativePlayer(detail.sessionId);
            }
            mediaState = VISUAL_STATES.includes(detail.state) ? detail.state : 'failed';
            if (Number.isSafeInteger(detail.channelId) && detail.channelId > 0) currentChannelId = detail.channelId;
            if (typeof detail.channelName === 'string' && detail.channelName) currentChannelName = detail.channelName;
            if (['movie', 'episode'].includes(detail.vodContentKind) && Number.isSafeInteger(detail.channelId) && detail.channelId > 0) {
                currentVodKind = detail.vodContentKind;
                currentVodMediaId = detail.channelId;
            }
            if (mediaState === 'idle') {
                destroyPlayer();
                currentChannelId = null;
                currentChannelName = '';
                currentVodKind = null;
                currentVodMediaId = null;
            } else if (mediaState === 'failed') {
                if (nativeSessionId) {
                    hideNativePlayerSurface();
                    const nativeControls = element('[data-iptv-native-controls]');
                    if (nativeControls) nativeControls.hidden = true;
                }
            }
            const stage = element('[data-iptv-player-stage]');
            const empty = element('[data-iptv-player-empty]');
            const state = element('[data-iptv-player-state]');
            const stateLabel = element('[data-iptv-player-state-label]');
            const error = element('[data-iptv-player-error]');
            if (stage) stage.dataset.state = mediaState;
            if (empty) empty.hidden = mediaState !== 'idle';
            if (state) state.hidden = !['preparing', 'loading', 'buffering', 'reconnecting'].includes(mediaState);
            if (stateLabel) stateLabel.textContent = mediaStatusLabel(mediaState, detail.errorCode);
            if (stateLabel) stateLabel.textContent = mediaState === 'reconnecting' ? 'Reconectando…' : 'Preparando canal…';
            if (stateLabel) stateLabel.textContent = mediaStatusLabel(mediaState, detail.errorCode);
            if (error) error.hidden = mediaState !== 'failed';
            const errorTitle = element('[data-iptv-error-title]');
            const errorCopy = element('[data-iptv-error-copy]');
            const retry = element('[data-iptv-retry]');
            const runtimeUnavailable = ['media_runtime_unavailable', 'mpv_runtime_unavailable'].includes(detail.errorCode);
            if (errorTitle) errorTitle.textContent = runtimeUnavailable ? 'Player local indisponível.' : 'Não foi possível reproduzir este conteúdo.';
            if (errorCopy) errorCopy.textContent = runtimeUnavailable
                ? 'Este dispositivo não possui os componentes necessários para a reprodução local.'
                : 'Tente novamente ou escolha outro conteúdo do catálogo.';
            if (retry) retry.hidden = runtimeUnavailable || !currentChannelId;
            if (nativeSessionId) {
                const wasPaused = nativePaused;
                if (typeof detail.paused === 'boolean') nativePaused = detail.paused;
                else if (mediaState === 'paused') nativePaused = true;
                else if (mediaState === 'playing') nativePaused = false;
                if (typeof detail.muted === 'boolean') nativeMuted = detail.muted;
                if (typeof detail.volume === 'number') nativeVolume = Math.min(100, Math.max(0, detail.volume));
                renderNativeControlModel({paused: nativePaused, muted: nativeMuted, volume: nativeVolume});
                const playing = ['streaming', 'playing'].includes(mediaState);
                if (nativePaused) {
                    clearHideTimer();
                    setControlsVisible(true);
                } else if (nativeSessionNeedsAutoHide && playing) {
                    nativeSessionNeedsAutoHide = false;
                    if (nativeFullscreen) userActivity();
                } else if (wasPaused && playing) {
                    scheduleHide();
                }
                const mediaKind = detail.mediaKind === 'vod' ? 'vod' : detail.mediaKind === 'live' ? 'live' : null;
                if (mediaKind) nativeMediaKind = mediaKind;
                root.querySelectorAll?.('[data-iptv-native-vod]').forEach(function (control) { control.hidden = mediaKind !== 'vod'; });
                root.querySelectorAll?.('[data-iptv-episode-nav]').forEach(function (control) {
                    const episode = mediaKind === 'vod' && detail.vodContentKind === 'episode';
                    control.hidden = !episode;
                    control.disabled = !episode || (control.dataset.iptvEpisodeNav === 'previous'
                        ? detail.canPreviousEpisode !== true
                        : detail.canNextEpisode !== true);
                });
                const context = element('[data-iptv-native-context]');
                if (context) context.textContent = mediaKind === 'vod' ? 'Vídeo' : mediaKind === 'live' ? 'Ao vivo' : 'Carregando';
                const nativeTitle = element('[data-iptv-native-title]');
                if (nativeTitle) nativeTitle.textContent = currentChannelName || 'Minha IPTV';
                const buffering = element('[data-iptv-native-buffering]');
                if (buffering) buffering.hidden = !['loading', 'buffering'].includes(mediaState);
                const timeline = element('[data-iptv-native-timeline]');
                if (timeline) timeline.textContent = mediaKind === 'vod'
                    ? formatMediaTime(detail.position) + ' / ' + formatMediaTime(detail.duration)
                    : '';
                const progress = element('[data-iptv-native-progress]');
                if (progress && mediaKind === 'vod') {
                    progress.max = String(Math.max(0, detail.duration || 0));
                    if (!nativeScrubbing) progress.value = String(Math.min(detail.position || 0, detail.duration || 0));
                    progress.disabled = detail.seekable !== true;
                }
                if (typeof detail.fullscreen === 'boolean') applyNativeFullscreenUi(detail.fullscreen);
                if (detail.sessionId && detail.position > 0 && nativeRecentSession !== detail.sessionId) {
                    nativeRecentSession = detail.sessionId;
                    if (activeView === 'recents') loadCurrentView('Atualizando recentes…');
                }
                if (mediaState === 'failed') hideNativePlayerSurface();
                else scheduleNativeGeometry();
            }
            updateActiveCards();
        }

        function startChannel(channelId, channelName) {
            if (!mediaAvailable || !Number.isSafeInteger(channelId) || channelId < 1) return;
            if (currentChannelId === channelId && ACTIVE_STATES.includes(mediaState)) return;
            currentChannelId = channelId;
            currentVodKind = null;
            currentVodMediaId = null;
            currentChannelName = channelName || 'Canal selecionado';
            nativePaused = false;
            renderMediaState({state: 'preparing', channelId, channelName: currentChannelName});
            request('view-start', {channelId});
            element('[data-iptv-player-stage]')?.scrollIntoView?.({behavior: 'smooth', block: 'center'});
        }

        function startVod(kind, mediaId, name) {
            if (!mediaAvailable || !['movie', 'episode'].includes(kind) || !Number.isSafeInteger(mediaId) || mediaId < 1) return;
            currentVodKind = kind;
            currentVodMediaId = mediaId;
            currentChannelId = mediaId;
            currentChannelName = name || 'Conteúdo selecionado';
            nativePaused = false;
            renderMediaState({state: 'preparing', channelId: mediaId, channelName: currentChannelName});
            request('vod-view-start', {kind, mediaId});
            element('[data-iptv-player-stage]')?.scrollIntoView?.({behavior: 'smooth', block: 'center'});
        }

        function stopPlayback() {
            const sessionId = nativeSessionId;
            destroyPlayer();
            renderMediaState({state: 'idle'});
            if (sessionId) request('player-control', {sessionId, command: 'stop'});
            else request('view-stop');
        }

        function chooseOtherContent() {
            const sessionId = nativeSessionId;
            if (sessionId) request('player-control', {sessionId, command: 'stop'});
            else if (currentChannelId !== null) request('view-stop');
            destroyPlayer();
            renderMediaState({state: 'idle'});
            element('[data-iptv-search-form] input[name="query"]')?.focus();
        }

        function leavePage() {
            if (navigationStopRequested) return;
            navigationStopRequested = true;
            const shouldStop = mediaAvailable && (currentChannelId !== null || ACTIVE_STATES.includes(mediaState));
            const sessionId = nativeSessionId;
            destroyPlayer();
            renderMediaState({state: 'idle'});
            if (shouldStop) {
                if (sessionId) request('player-control', {sessionId, command: 'stop'});
                else request('view-stop');
            }
        }

        function toggleFullscreen() {
            if (nativeSessionId) request('player-fullscreen', {sessionId: nativeSessionId, fullscreen: !nativeFullscreen});
        }

        function applyNativeFullscreenUi(fullscreen) {
            const nextFullscreen = fullscreen === true;
            const changed = nativeFullscreen !== nextFullscreen;
            nativeFullscreen = nextFullscreen;
            root?.classList?.toggle('is-native-fullscreen', nativeFullscreen);
            setDocumentFullscreenLock(nativeFullscreen);
            if (!nativeFullscreen) root?.classList?.remove?.('is-native-controls-hidden');
            const button = element('[data-iptv-native-fullscreen]');
            if (button) {
                button.setAttribute('aria-label', nativeFullscreen ? 'Sair da tela cheia' : 'Entrar em tela cheia');
                button.title = nativeFullscreen ? 'Sair da tela cheia' : 'Entrar em tela cheia';
            }
            const expand = element('[data-iptv-native-fullscreen-icon]');
            const compress = element('[data-iptv-native-compress-icon]');
            if (expand) expand.hidden = nativeFullscreen;
            if (compress) compress.hidden = !nativeFullscreen;
            if (changed && nativeFullscreen) userActivity();
            if (changed && !nativeFullscreen) {
                clearHideTimer();
                setControlsVisible(true);
            }
            scheduleNativeGeometry();
        }

        function setDocumentFullscreenLock(active) {
            const html = document.documentElement;
            const body = document.body;
            if (active && fullscreenScrollPosition === null) {
                fullscreenScrollPosition = {
                    x: Number(target.scrollX) || 0,
                    y: Number(target.scrollY) || 0,
                };
            }
            html?.classList?.toggle?.('semyra-player-fullscreen', active);
            body?.classList?.toggle?.('semyra-player-fullscreen', active);
            if (!active && fullscreenScrollPosition !== null) {
                const position = fullscreenScrollPosition;
                fullscreenScrollPosition = null;
                target.scrollTo?.(position.x, position.y);
            }
        }

        function clearHideTimer() {
            if (hideTimer === null) return;
            if (typeof target.clearTimeout === 'function') target.clearTimeout(hideTimer);
            else clearTimeout(hideTimer);
            hideTimer = null;
        }

        function setControlsVisible(visible) {
            const stage = element('[data-iptv-player-stage]');
            if (!stage) return;
            const hidden = nativeFullscreen && !visible;
            if (controlsVisible === visible
                && root?.classList?.contains?.('is-native-controls-hidden') === hidden) return;
            controlsVisible = visible;
            root?.classList?.toggle?.('is-native-controls-hidden', hidden);
            stage.dataset.controlsHidden = hidden ? 'true' : 'false';
            reportControlsVisibility(hidden);
            reportNativeGeometry();
        }

        function scheduleHide() {
            clearHideTimer();
            if (!nativeFullscreen
                || !['streaming', 'playing'].includes(mediaState)
                || nativePaused) return;
            const elapsed = Math.max(0, Date.now() - lastUserActivity);
            hideTimer = target.setTimeout(hideControls, Math.max(0, CONTROLS_AUTO_HIDE_MS - elapsed));
        }

        function hideControls() {
            hideTimer = null;
            if (!nativeFullscreen || nativePaused || !['streaming', 'playing'].includes(mediaState)) return;
            if (nativeScrubbing || volumeAdjusting) {
                lastUserActivity = Date.now();
                scheduleHide();
                return;
            }
            setControlsVisible(false);
        }

        function userActivity() {
            lastUserActivity = Date.now();
            setControlsVisible(true);
            scheduleHide();
        }

        function keyboardContextConsumesPlayerShortcut(event) {
            return [event?.target, document.activeElement].filter(Boolean).some(function (candidate) {
                const tag = candidate.tagName?.toLowerCase?.();
                if (['input', 'textarea', 'select', 'option', 'button'].includes(tag)) return true;
                if (candidate.isContentEditable === true) return true;
                return typeof candidate.closest === 'function'
                    && candidate.closest('input, textarea, select, button, [contenteditable="true"], [role="button"], [role="dialog"]') !== null;
            });
        }

        function playerControlFromPointerEvent(controls, event) {
            const control = event?.target?.closest?.('button, input[type="range"]');
            return control && controls.contains(control) ? control : null;
        }

        function bindPlayerPointerFocusRelease() {
            const controls = element('[data-iptv-native-controls]');
            if (!controls) return;
            let pointerControl = null;
            controls.addEventListener('pointerdown', function (event) {
                pointerControl = playerControlFromPointerEvent(controls, event);
            });
            controls.addEventListener('pointercancel', function () {
                pointerControl = null;
            });
            controls.addEventListener('pointerup', function (event) {
                const control = playerControlFromPointerEvent(controls, event);
                if (!control || control !== pointerControl || control.tagName?.toLowerCase?.() !== 'input' || control.type !== 'range') return;
                pointerControl = null;
                if (document.activeElement === control) control.blur?.();
            });
            controls.addEventListener('click', function (event) {
                const control = playerControlFromPointerEvent(controls, event);
                if (!control || control !== pointerControl || control.tagName?.toLowerCase?.() !== 'button') return;
                pointerControl = null;
                if (document.activeElement === control) control.blur?.();
            });
        }

        function loadSources() {
            listRequestToken = nextRequestToken('list');
            request('list', {}, listRequestToken);
        }

        function isCurrentResult(detail, token) {
            return token === null || detail.clientRequestId === token;
        }

        function updateFavoriteButtons(channelId, favorite, pending) {
            if (!root || typeof root.querySelectorAll !== 'function') return;
            root.querySelectorAll('[data-iptv-favorite]').forEach(function (button) {
                if (Number(button.dataset.iptvFavorite) !== channelId) return;
                button.disabled = Boolean(pending);
                button.setAttribute('aria-busy', pending ? 'true' : 'false');
                if (pending) return;
                button.dataset.favorite = favorite ? 'true' : 'false';
                button.setAttribute('aria-pressed', favorite ? 'true' : 'false');
                button.setAttribute('aria-label', favorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos');
                button.title = favorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos';
            });
        }

        function updateRecentRemoveButton(recentId, pending) {
            if (!root || typeof root.querySelectorAll !== 'function') return;
            root.querySelectorAll('[data-iptv-recent-remove]').forEach(function (button) {
                if (Number(button.dataset.recentId) !== recentId) return;
                button.disabled = Boolean(pending);
                button.setAttribute('aria-busy', pending ? 'true' : 'false');
            });
        }

        function removeRecentLocally(recentId) {
            renderedChannels = renderedChannels.filter(function (channel) { return channel.recentId !== recentId; });
            renderChannels(renderedChannels, renderedHasMore);
        }

        function requestRecentRemove(button) {
            if (!button || button.disabled || activeView !== 'recents' || !selectedSourceId) return;
            const recentId = Number(button.dataset.recentId);
            if (!Number.isSafeInteger(recentId) || recentId < 1) return;
            const token = nextRequestToken('recent-remove');
            recentRemoveRequests.set(token, {recentId, sourceId: selectedSourceId});
            updateRecentRemoveButton(recentId, true);
            recentsFeedback('', false);
            request('recent-remove', {sourceId: selectedSourceId, recentId}, token);
        }

        function requestRecentsClear() {
            if (recentsClearRequest || activeView !== 'recents' || !selectedSourceId || renderedChannels.length === 0) return;
            recentsConfirming = true;
            recentsFeedback('', false);
            renderRecentsActions();
        }

        function cancelRecentsClear() {
            if (recentsClearRequest) return;
            recentsConfirming = false;
            renderRecentsActions();
        }

        function confirmRecentsClear() {
            if (recentsClearRequest || !recentsConfirming || activeView !== 'recents' || !selectedSourceId || renderedChannels.length === 0) return;
            const token = nextRequestToken('recents-clear');
            recentsClearRequest = {token, sourceId: selectedSourceId};
            recentsFeedback('', false);
            renderRecentsActions();
            request('recents-clear', {sourceId: selectedSourceId}, token);
        }

        function onResult(event) {
            const detail = event && event.detail;
            if (!detail || typeof detail.action !== 'string') return;
            if (detail.action === 'list' && !isCurrentResult(detail, listRequestToken)) return;
            if (detail.action === 'groups' && !isCurrentResult(detail, groupsRequestToken)) return;
            if (['search', 'favorites', 'recents'].includes(detail.action)
                && (detail.action !== viewRequestAction || !isCurrentResult(detail, searchRequestToken))) return;
            const vodView = detail.action === 'vod-movies-search' ? 'movies' : detail.action === 'vod-series-search' ? 'series' : null;
            if (vodView && vodState[vodView].requestToken !== detail.clientRequestId) return;
            if (detail.action === 'vod-categories' && !Object.values(vodState).some(function (state) { return state.categoryToken === detail.clientRequestId; })) return;
            if (detail.action === 'vod-series-details' && vodState.series.detailsToken !== detail.clientRequestId) return;
            if (detail.action === 'vod-continue' && continueState.requestToken !== detail.clientRequestId) return;
            const continueDismissPending = detail.action === 'vod-continue-dismiss' ? continueDismissRequests.get(detail.clientRequestId) : null;
            if (detail.action === 'vod-continue-dismiss' && !continueDismissPending) return;
            const favoritePending = detail.action === 'favorite-set' ? favoriteRequests.get(detail.clientRequestId) : null;
            if (detail.action === 'favorite-set' && !favoritePending) return;
            const recentPending = detail.action === 'recent-remove' ? recentRemoveRequests.get(detail.clientRequestId) : null;
            if (detail.action === 'recent-remove' && !recentPending) return;
            const clearPending = detail.action === 'recents-clear' && recentsClearRequest?.token === detail.clientRequestId
                ? recentsClearRequest : null;
            if (detail.action === 'recents-clear' && !clearPending) return;
            const ownedBySettings = operationOwns(detail);
            if (settingsOperation && settingsOperation.tokens.has(detail.action) && !ownedBySettings) return;
            if (!detail.ok) {
                if (detail.action === 'vod-continue-dismiss') {
                    continueDismissRequests.delete(detail.clientRequestId);
                    if (continueDismissPending.sourceId === selectedSourceId) renderContinueWatching(continueDismissPending.view);
                }
                if (['view-start', 'vod-view-start'].includes(detail.action)) renderMediaState({state: 'failed', errorCode: detail.error});
                else if (vodView) {
                    const list = element('[data-iptv-vod-list="' + vodView + '"]');
                    if (list) list.replaceChildren(vodEmpty(vodView, 'Não foi possível carregar este catálogo.'));
                } else if (detail.action === 'vod-series-details') {
                    const episodes = element('[data-iptv-series-episodes]');
                    if (episodes) episodes.replaceChildren(node('p', 'iptv-vod-warning', 'Não foi possível carregar os episódios. Tente novamente.'));
                }
                else if (['search', 'favorites', 'recents'].includes(detail.action)) {
                    setCatalogLoading(false);
                    const list = element('[data-iptv-channel-list]');
                    const message = detail.action === 'favorites'
                        ? 'Não foi possível carregar os favoritos.'
                        : detail.action === 'recents'
                            ? 'Não foi possível carregar os recentes.'
                            : 'Não foi possível carregar os canais. Tente novamente.';
                    if (list) list.replaceChildren(renderChannelEmpty(message));
                    const sourceSelect = element('[data-iptv-source-select]');
                    if (sourceSelect) sourceSelect.disabled = false;
                } else if (detail.action === 'favorite-set') {
                    favoriteRequests.delete(detail.clientRequestId);
                    updateFavoriteButtons(favoritePending.channelId, favoritePending.previous, false);
                    feedback('Não foi possível atualizar os favoritos.', true);
                } else if (detail.action === 'recent-remove') {
                    recentRemoveRequests.delete(detail.clientRequestId);
                    if (recentPending.sourceId === selectedSourceId && activeView === 'recents') {
                        updateRecentRemoveButton(recentPending.recentId, false);
                        recentsFeedback('Não foi possível remover este item dos recentes.', true);
                    }
                } else if (detail.action === 'recents-clear') {
                    recentsClearRequest = null;
                    renderRecentsActions();
                    if (clearPending.sourceId === selectedSourceId && activeView === 'recents') {
                        recentsFeedback('Não foi possível limpar os recentes.', true);
                    }
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
            } else if (detail.action === 'add-xtream') {
                if (!ownedBySettings || !detail.source || !Number.isSafeInteger(detail.source.id)) return;
                upsertSource(detail.source);
                renderSources();
                settingsOperation.sourceId = detail.source.id;
                loadSourceForOperation(detail.source.id, true, '');
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
            } else if (detail.action === 'vod-refresh') {
                if (!ownedBySettings || !detail.source || !Number.isSafeInteger(detail.source.id)) return;
                upsertSource(detail.source);
                renderSources();
                loadContinueWatching();
                if (primaryView !== 'live') loadVod(primaryView, true);
                finishSettingsOperation('Filmes e séries sincronizados.', false, false);
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
            } else if (detail.action === 'vod-categories') {
                const view = vodState.series.categoryToken === detail.clientRequestId ? 'series' : 'movies';
                vodState[view].categories = detail.categories || [];
                renderVodCategories(view);
            } else if (vodView) {
                vodState[vodView].items = detail.items || [];
                vodState[vodView].offset = detail.offset || 0;
                vodState[vodView].hasMore = detail.hasMore === true;
                renderVod(vodView);
            } else if (detail.action === 'vod-series-details') {
                renderSeriesEpisodes(detail.episodes || [], detail.stale === true);
            } else if (detail.action === 'vod-continue') {
                continueState.movies = detail.movies || [];
                continueState.series = detail.series || [];
                renderContinueWatching('movies');
                renderContinueWatching('series');
            } else if (detail.action === 'vod-continue-dismiss') {
                continueDismissRequests.delete(detail.clientRequestId);
                if (continueDismissPending.sourceId === selectedSourceId) {
                    if (detail.dismissed) {
                        const key = continueDismissPending.view === 'movies' ? 'id' : 'seriesId';
                        continueState[continueDismissPending.view] = continueState[continueDismissPending.view].filter(function (item) {
                            return item[key] !== continueDismissPending.mediaId;
                        });
                    }
                    renderContinueWatching(continueDismissPending.view);
                }
            } else if (['search', 'favorites', 'recents'].includes(detail.action)) {
                if (Number.isSafeInteger(detail.offset)) offset = detail.offset;
                renderChannels(detail.channels || [], detail.hasMore);
                const sourceSelect = element('[data-iptv-source-select]');
                if (sourceSelect) sourceSelect.disabled = false;
                if (ownedBySettings) settleSourceLoad(detail.action);
            } else if (detail.action === 'favorite-set') {
                favoriteRequests.delete(detail.clientRequestId);
                if (favoritePending.sourceId !== selectedSourceId) return;
                updateFavoriteButtons(favoritePending.channelId, detail.favorite, false);
                if (activeView === 'favorites' && !detail.favorite) loadCurrentView('Atualizando favoritos…');
            } else if (detail.action === 'recent-remove') {
                recentRemoveRequests.delete(detail.clientRequestId);
                if (recentPending.sourceId !== selectedSourceId || activeView !== 'recents') return;
                if (!detail.removed) {
                    updateRecentRemoveButton(recentPending.recentId, false);
                    recentsFeedback('Este item não estava mais nos recentes.', true);
                    return;
                }
                removeRecentLocally(recentPending.recentId);
                recentsFeedback('', false);
            } else if (detail.action === 'recents-clear') {
                recentsClearRequest = null;
                recentsConfirming = false;
                renderRecentsActions();
                if (clearPending.sourceId !== selectedSourceId || activeView !== 'recents') return;
                renderChannels([], false);
                recentsFeedback('', false);
            } else if (['view-start', 'vod-view-start', 'vod-navigate', 'view-stop', 'view-status'].includes(detail.action)) {
                if (detail.action === 'view-stop') destroyPlayer();
                renderMediaState(detail);
            } else if (detail.action === 'player-control') {
                renderMediaState(detail);
            } else if (detail.action === 'player-fullscreen') {
                if (detail.sessionId === nativeSessionId) renderMediaState(detail);
            }
        }

        function onMediaState(event) {
            if (!event || !event.detail) return;
            if (event.detail.playbackKind === 'native-mpv' && nativeSessionId && event.detail.sessionId !== nativeSessionId) {
                const autoplayTransition = mediaState === 'ended'
                    && event.detail.state === 'loading'
                    && event.detail.vodContentKind === 'episode';
                if (!autoplayTransition) return;
            }
            renderMediaState(event.detail);
            if (['idle', 'ended'].includes(event.detail.state)) loadContinueWatching();
            if (event.detail.playbackKind !== 'native-mpv' && activeView === 'recents'
                && ['streaming', 'playing'].includes(event.detail.state) && event.detail.mode === 'view') {
                loadCurrentView('Atualizando recentes…');
            }
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
            target.addEventListener('semyra:iptv-vod-progress-changed', onVodProgressChanged);

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
            bind('[data-iptv-xtream-form]', 'submit', function (event) {
                event.preventDefault();
                const form = event.currentTarget;
                const button = form.querySelector ? form.querySelector('button[type="submit"]') : null;
                const detail = {
                    name: form.elements.name.value.trim(),
                    baseUrl: form.elements.baseUrl.value.trim(),
                    username: form.elements.username.value.trim(),
                    password: form.elements.password.value,
                };
                if (!detail.name || !detail.baseUrl || !detail.username || !detail.password) {
                    feedback('Informe nome, servidor, usuÃ¡rio e senha do Xtream.', true);
                    return;
                }
                if (!beginSettingsOperation('adding-xtream', button)) return;
                operationRequest('add-xtream', detail);
                form.elements.password.value = '';
            });
            bind('[data-iptv-pick-file]', 'click', function (event) {
                if (!beginSettingsOperation('adding-file', event.currentTarget)) return;
                operationRequest('pick-file', {});
            });
            bind('[data-iptv-source-select]', 'change', function (event) { selectSource(Number(event.currentTarget.value)); });
            bind('[data-iptv-primary-tabs]', 'click', function (event) {
                const tab = event.target.closest('[data-iptv-primary]'); if (tab) setPrimaryView(tab.dataset.iptvPrimary, false);
            });
            bind('[data-iptv-primary-tabs]', 'keydown', function (event) {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                const views = currentSource()?.type === 'xtream' ? ['live', 'movies', 'series'] : ['live'];
                event.preventDefault(); let index = views.indexOf(primaryView);
                if (event.key === 'Home') index = 0; else if (event.key === 'End') index = views.length - 1; else index = (index + (event.key === 'ArrowRight' ? 1 : -1) + views.length) % views.length;
                setPrimaryView(views[index], true);
            });
            bind('[data-iptv-view-tabs]', 'click', function (event) {
                const tab = event.target.closest('[data-iptv-view]');
                if (tab) setActiveView(tab.dataset.iptvView, false);
            });
            bind('[data-iptv-view-tabs]', 'keydown', function (event) {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const views = ['favorites', 'recents', 'all'];
                let index = views.indexOf(activeView);
                if (event.key === 'Home') index = 0;
                else if (event.key === 'End') index = views.length - 1;
                else index = (index + (event.key === 'ArrowRight' ? 1 : -1) + views.length) % views.length;
                setActiveView(views[index], true);
            });
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
                if (button.dataset.iptvAction === 'vod-refresh') {
                    if (!beginSettingsOperation('syncing-vod', button, sourceId)) return;
                    operationRequest('vod-refresh', {sourceId});
                }
                if (button.dataset.iptvAction === 'remove'
                    && target.confirm('Remover esta fonte IPTV deste dispositivo?\n\nO catálogo local associado também será removido.')) {
                    if (!beginSettingsOperation('removing-source', button, sourceId)) return;
                    operationRequest('remove', {sourceId});
                }
            });
            bind('[data-iptv-channel-list]', 'click', function (event) {
                const recent = event.target.closest('[data-iptv-recent-remove]');
                if (recent) {
                    event.preventDefault?.();
                    event.stopPropagation?.();
                    requestRecentRemove(recent);
                    return;
                }
                const favorite = event.target.closest('[data-iptv-favorite]');
                if (favorite) {
                    event.stopPropagation?.();
                    if (favorite.disabled) return;
                    const channelId = Number(favorite.dataset.iptvFavorite);
                    const previous = favorite.dataset.favorite === 'true';
                    const token = nextRequestToken('favorite-set');
                    favoriteRequests.set(token, {channelId, previous, sourceId: selectedSourceId});
                    updateFavoriteButtons(channelId, previous, true);
                    request('favorite-set', {channelId, favorite: !previous}, token);
                    return;
                }
                const card = event.target.closest('[data-iptv-view-start]');
                if (!card) return;
                const name = card.querySelector('.iptv-channel-copy strong');
                startChannel(Number(card.dataset.iptvViewStart), name ? name.textContent : '');
            });
            root.addEventListener('click', function (event) {
                const settings = event.target.closest('[data-iptv-vod-settings]'); if (settings) { openSettings(); return; }
                const back = event.target.closest('[data-iptv-vod-back]');
                if (back) { const view = back.dataset.iptvVodBack; element('[data-iptv-vod-detail="' + view + '"]').hidden = true; element('[data-iptv-vod-browser="' + view + '"]').hidden = false; element('[data-iptv-vod-open="' + view + '"]')?.focus?.(); return; }
                const dismiss = event.target.closest('[data-iptv-continue-dismiss]');
                if (dismiss) {
                    event.preventDefault?.();
                    event.stopPropagation?.();
                    if (dismiss.disabled || !selectedSourceId) return;
                    const view = dismiss.dataset.iptvContinueDismiss;
                    const mediaId = Number(dismiss.dataset.mediaId);
                    if (!['movies', 'series'].includes(view) || !Number.isSafeInteger(mediaId) || mediaId < 1) return;
                    const token = nextRequestToken('vod-continue-dismiss');
                    continueDismissRequests.set(token, {sourceId: selectedSourceId, view, mediaId});
                    dismiss.disabled = true;
                    dismiss.setAttribute('aria-busy', 'true');
                    request('vod-continue-dismiss', {sourceId: selectedSourceId, kind: view === 'movies' ? 'movie' : 'series', mediaId}, token);
                    return;
                }
                const continuedSeries = event.target.closest('[data-iptv-continue-series]');
                if (continuedSeries) {
                    const seriesId = Number(continuedSeries.dataset.iptvContinueSeries);
                    const entry = continueState.series.find(function (candidate) { return candidate.seriesId === seriesId; });
                    if (entry) {
                        const item = vodState.series.items.find(function (candidate) { return candidate.id === seriesId; })
                            || {id: seriesId, name: entry.name, posterUrl: entry.posterUrl};
                        showVodDetail('series', item, {episodeId: entry.episodeId, seasonNumber: entry.seasonNumber});
                    }
                    return;
                }
                const open = event.target.closest('[data-iptv-vod-open]');
                if (open) { const view = open.dataset.iptvVodOpen; const item = vodState[view].items.find(function (candidate) { return candidate.id === Number(open.dataset.mediaId); }); if (item) showVodDetail(view, item); return; }
                const play = event.target.closest('[data-iptv-vod-play]');
                if (play) {
                    const selected = play.dataset.iptvVodPlay === 'movie' ? vodState.movies.selected : vodState.series.selected;
                    startVod(play.dataset.iptvVodPlay, Number(play.dataset.mediaId), play.dataset.mediaName || selected?.name || 'Conteúdo selecionado');
                }
                const seasonTab = event.target.closest('[data-iptv-season-tab]');
                if (seasonTab) {
                    root.querySelectorAll('[data-iptv-season-tab]').forEach(function (tab) { const selected = tab === seasonTab; tab.classList.toggle('is-active', selected); tab.setAttribute('aria-selected', selected ? 'true' : 'false'); });
                    root.querySelectorAll('[data-iptv-season]').forEach(function (section) { section.hidden = section.dataset.iptvSeason !== seasonTab.dataset.iptvSeasonTab; });
                }
            });
            ['movies', 'series'].forEach(function (view) {
                bind('[data-iptv-vod-form="' + view + '"]', 'submit', function (event) { event.preventDefault(); vodState[view].offset = 0; loadVod(view, false); });
                bind('[data-iptv-vod-form="' + view + '"] select[name="category"]', 'change', function () { vodState[view].offset = 0; loadVod(view, false); });
                bind('[data-iptv-vod-previous="' + view + '"]', 'click', function () { vodState[view].offset = Math.max(0, vodState[view].offset - limit); loadVod(view, false); });
                bind('[data-iptv-vod-next="' + view + '"]', 'click', function () { vodState[view].offset += limit; loadVod(view, false); });
            });
            bind('[data-iptv-recents-clear]', 'click', requestRecentsClear);
            bind('[data-iptv-recents-cancel]', 'click', cancelRecentsClear);
            bind('[data-iptv-recents-confirm-clear]', 'click', confirmRecentsClear);
            bind('[data-iptv-native-stop]', 'click', stopPlayback);
            bind('[data-iptv-native-playback]', 'click', function (event) { sendNativeControl(event.currentTarget.dataset.command); });
            bind('[data-iptv-native-seek="-30"]', 'click', function () { sendNativeControl('seek-relative', -30); });
            bind('[data-iptv-native-seek="30"]', 'click', function () { sendNativeControl('seek-relative', 30); });
            root.querySelectorAll?.('[data-iptv-episode-nav]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!button.disabled) request('vod-navigate', {direction: button.dataset.iptvEpisodeNav});
                });
            });
            bind('[data-iptv-native-mute]', 'click', function () { sendNativeControl(nativeMuted ? 'unmute' : 'mute'); });
            bind('[data-iptv-native-volume]', 'input', function (event) {
                volumeAdjusting = true;
                nativeVolume = syncRangeVisual(event.currentTarget, Number(event.currentTarget.value));
                sendNativeControl('volume', nativeVolume);
            });
            bind('[data-iptv-native-volume]', 'pointerdown', function () { volumeAdjusting = true; userActivity(); });
            bind('[data-iptv-native-volume]', 'pointerup', function () { volumeAdjusting = false; userActivity(); });
            bind('[data-iptv-native-volume]', 'change', function () { volumeAdjusting = false; userActivity(); });
            bind('[data-iptv-native-progress]', 'pointerdown', function () { nativeScrubbing = true; userActivity(); });
            bind('[data-iptv-native-progress]', 'input', function (event) {
                nativeScrubbing = true;
                userActivity();
                const timeline = element('[data-iptv-native-timeline]');
                if (timeline) timeline.textContent = formatMediaTime(Number(event.currentTarget.value)) + ' / ' + formatMediaTime(Number(event.currentTarget.max));
            });
            bind('[data-iptv-native-progress]', 'change', function (event) {
                sendNativeControl('seek-absolute', Number(event.currentTarget.value));
                nativeScrubbing = false;
                userActivity();
            });
            bind('[data-iptv-native-fullscreen]', 'click', toggleFullscreen);
            bind('[data-iptv-retry]', 'click', function () { if (currentVodKind && currentVodMediaId) startVod(currentVodKind, currentVodMediaId, currentChannelName); else if (currentChannelId) startChannel(currentChannelId, currentChannelName); });
            bind('[data-iptv-choose-channel]', 'click', chooseOtherContent);
            bind('[data-iptv-search-form]', 'submit', function (event) { event.preventDefault(); offset = 0; search('Buscando canais…'); });
            bind('[data-iptv-search-form] select[name="group"]', 'change', function () { offset = 0; search('Aplicando filtro…'); });
            bind('[data-iptv-previous]', 'click', function () { offset = Math.max(0, offset - limit); loadCurrentView('Carregando página anterior…'); });
            bind('[data-iptv-next]', 'click', function () { offset += limit; loadCurrentView('Carregando próxima página…'); });

            bindPlayerPointerFocusRelease();

            const stage = element('[data-iptv-player-stage]');
            ['pointermove', 'pointerdown', 'keydown'].forEach(function (type) { stage?.addEventListener(type, userActivity); });
            ['pointermove', 'pointerdown', 'keydown'].forEach(function (type) { root?.addEventListener?.(type, userActivity); });
            target.addEventListener?.('semyra:player-user-activity', userActivity);
            document.addEventListener?.('keydown', function (event) {
                const settings = element('[data-iptv-settings]');
                if (event.repeat === true || settings?.open === true || keyboardContextConsumesPlayerShortcut(event)) return;
                const openDetail = primaryView !== 'live' ? element('[data-iptv-vod-detail="' + primaryView + '"]:not([hidden])') : null;
                if (event.key === 'Escape' && openDetail && !nativeFullscreen) {
                    const back = openDetail.querySelector?.('[data-iptv-vod-back]'); back?.click?.();
                } else if (nativeSessionId && (event.key === 'f' || event.key === 'F')) {
                    event.preventDefault?.();
                    toggleFullscreen();
                } else if (nativeSessionId && nativeFullscreen && event.key === 'Escape') {
                    event.preventDefault?.();
                    request('player-fullscreen', {sessionId: nativeSessionId, fullscreen: false});
                } else if (nativeSessionId && nativeMediaKind === 'vod' && (event.key === ' ' || event.code === 'Space')) {
                    event.preventDefault?.();
                    sendNativeControl(nativePaused ? 'play' : 'pause');
                } else if (nativeSessionId && nativeMediaKind === 'vod' && event.key === 'ArrowLeft') {
                    event.preventDefault?.();
                    sendNativeControl('seek-relative', -30);
                } else if (nativeSessionId && nativeMediaKind === 'vod' && event.key === 'ArrowRight') {
                    event.preventDefault?.();
                    sendNativeControl('seek-relative', 30);
                }
            });
            target.addEventListener('pagehide', leavePage);
            target.addEventListener('resize', scheduleNativeGeometry);
            target.addEventListener('scroll', scheduleNativeGeometry, true);
            document.querySelector('form.account-logout[action="/logout"]')?.addEventListener('submit', destroyPlayer);

            fallbackTimer = target.setTimeout(function () {
                if (activated) return;
                const notice = element('[data-iptv-browser-notice]');
                const panel = element('[data-iptv-desktop-panel]');
                if (notice) notice.hidden = false;
                if (panel) panel.hidden = true;
            }, 1200);

            renderActiveView();
            renderPrimaryNavigation();
            const snapshot = target.SemyraDesktopBridge && target.SemyraDesktopBridge.getHostSnapshot();
            if (snapshot && snapshot.capabilities.includes('iptv.sources')) activate();
            return true;
        }

        return Object.freeze({start});
    }

    return Object.freeze({
        createIptvCatalog,
        mediaStatusLabel,
        localViewAvailable,
        formatMediaTime,
        channelMonogram,
        shouldRenderRemoteLogo,
        syncRangeVisual,
        groupEpisodesBySeason,
        displayEpisodeDuration,
    });
}));
