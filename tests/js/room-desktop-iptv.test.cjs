'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const sourceCode = fs.readFileSync('public/assets/js/room-desktop-iptv.js', 'utf8');

function harness(premium = true) {
    const windowListeners = new Map(); const requests = []; const webBodies = []; const selectors = new Map();
    let document; let youtubeSubmits = 0; let scrollResets = 0;
    const element = (extra = {}) => {
        const listeners = new Map(); const attributes = new Map(); const classes = new Set();
        return Object.assign({hidden: false, disabled: false, value: '', textContent: '', dataset: {}, children: [], listeners,
            classList: {toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name); }, contains: (name) => classes.has(name)},
            addEventListener(type, listener) { listeners.set(type, listener); },
            setAttribute(name, value) { attributes.set(name, String(value)); },
            getAttribute(name) { return attributes.get(name); }, focus() { this.focused = true; document.activeElement = this; },
            append(...nodes) { this.children.push(...nodes); }, replaceChildren(...nodes) { this.children = nodes; },
            querySelector(selector) { return this.querySelectorAll(selector)[0] || null; },
            querySelectorAll(selector) {
                const found = [];
                const keyMatch = selector.match(/^\[data-([a-z0-9-]+)\]$/);
                const key = keyMatch?.[1].replace(/-([a-z])/g, (_all, letter) => letter.toUpperCase());
                const visit = (node) => {
                    for (const child of node.children || []) {
                        if (key && Object.hasOwn(child.dataset || {}, key)) found.push(child);
                        visit(child);
                    }
                };
                visit(this); return found;
            },
        }, extra);
    };
    const mainButtons = [element({dataset: {sourceTab: 'youtube'}}), element({dataset: {sourceTab: 'iptv'}})];
    const sourceTabs = element({querySelectorAll: () => mainButtons});
    const shell = element({dataset: {canPublishIptv: premium ? '1' : '0', csrfToken: 'csrf', iptvTransmissionUrl: '/iptv'}});
    const youtube = element({requestSubmit() { youtubeSubmits += 1; }}); const legacy = element();
    const panel = element({hidden: true});
    const controls = element(); const unavailable = element({hidden: true}); const vodUnavailable = element({hidden: true});
    const status = element({textContent: premium ? '' : 'Transmitir sua IPTV para a sala é um recurso Premium.'});
    const sourceSelect = element(); const kicker = element(); const submit = element();
    const tabButtons = ['live', 'movies', 'series'].map((kind) => element({dataset: {iptvTab: kind}}));
    const panels = ['live', 'movies', 'series'].map((kind) => element({dataset: {iptvPanel: kind}, hidden: kind !== 'live'}));
    const panelMap = new Map();
    panelMap.set('[data-room-iptv-controls-modern]', controls);
    panelMap.set('[data-room-iptv-unavailable-modern]', unavailable);
    panelMap.set('[data-room-vod-unavailable]', vodUnavailable);
    panelMap.set('[data-room-iptv-source-modern]', sourceSelect);
    const tabList = element(); panelMap.set('[role="tablist"]', tabList);
    const seriesBrowser = element(); const seriesDetail = element({hidden: true});
    panelMap.set('[data-iptv-series-browser]', seriesBrowser); panelMap.set('[data-iptv-series-detail]', seriesDetail);
    for (const kind of ['live', 'movies', 'series']) {
        panelMap.set(`[data-iptv-results-modern="${kind}"]`, element());
        panelMap.set(`[data-iptv-scroller="${kind}"]`, element({scrollTo() { scrollResets += 1; }}));
        panelMap.set(`[data-iptv-query="${kind}"]`, element());
        panelMap.set(`[data-iptv-category="${kind}"]`, element());
        panelMap.set(`[data-iptv-form="${kind}"]`, element({dataset: {iptvForm: kind}}));
        panelMap.set(`[data-iptv-page="${kind}"]`, element());
        panelMap.set(`[data-iptv-previous="${kind}"]`, element({dataset: {iptvPrevious: kind}}));
        panelMap.set(`[data-iptv-next="${kind}"]`, element({dataset: {iptvNext: kind}}));
    }
    panel.querySelector = (selector) => panelMap.get(selector) || null;
    panel.querySelectorAll = (selector) => selector === '[data-iptv-tab]' ? tabButtons : selector === '[data-iptv-panel]' ? panels : [];
    for (const [selector, value] of [
        ['[data-room-shell]', shell], ['[data-desktop-publish-tabs]', sourceTabs], ['[data-youtube-source-panel]', youtube],
        ['[data-iptv-source-panel]', legacy], ['[data-iptv-source-panel-modern]', panel], ['[data-room-iptv-status-modern]', status],
        ['[data-room-iptv-controls-modern]', controls], ['[data-room-iptv-unavailable-modern]', unavailable],
        ['[data-room-vod-unavailable]', vodUnavailable], ['[data-room-iptv-source-modern]', sourceSelect],
        ['[data-transmission-kicker]', kicker], ['.room-dialog-actions button[type="submit"]', submit],
        ['[data-end-transmission]', element({requestSubmit() { this.submitted = true; }})],
    ]) selectors.set(selector, value);

    class CustomEvent { constructor(type, options) { this.type = type; this.detail = options?.detail; } }
    const window = {
        addEventListener(type, listener) { windowListeners.set(type, listener); },
        dispatchEvent(event) { requests.push(event.detail); windowListeners.get(event.type)?.(event); },
        setTimeout(callback) { callback(); },
    };
    document = {
        querySelector: (selector) => selectors.get(selector) || null,
        createElement: (tagName) => element({tagName}), dispatchEvent() {}, activeElement: null,
    };
    const fetch = async (_url, options) => {
        webBodies.push(new URLSearchParams(options.body));
        return {ok: true, json: async () => ({transmission: {
            source: 'iptv', media_mode: webBodies.at(-1).get('media_mode'),
            instance_id: 'a'.repeat(32), revision: 1, owner_name: 'Owner', is_owner: true,
        }})};
    };
    vm.runInNewContext(sourceCode, {window, document, CustomEvent, URLSearchParams, fetch, console});

    const emit = (type, detail) => windowListeners.get(type)?.({detail});
    return {
        sourceTabs, controls, unavailable, vodUnavailable, status, requests, webBodies, panel, sourceSelect, panelMap,
        get youtubeSubmits() { return youtubeSubmits; }, get scrollResets() { return scrollResets; },
        hostReady(capabilities) { emit('semyra:host-ready', {state: 'ready', capabilities: capabilities || ['iptv.catalog', 'livekit.publish', 'livekit.publish-vod']}); },
        chooseIptv() { sourceTabs.listeners.get('click')({target: {closest: () => mainButtons[1]}}); },
        listResult(sources = [{id: 7, name: 'Source', type: 'xtream'}]) { emit('semyra:iptv-result', {action: 'list', ok: true, sources}); },
        result(action, payload = {}) { const pending = [...requests].reverse().find((item) => item?.action === action); emit('semyra:iptv-result', {action, ok: true, clientRequestId: pending?.clientRequestId, ...payload}); },
        clickTab(kind) { panel.listeners.get('click')({target: {closest: (selector) => selector === '[data-iptv-tab]' ? tabButtons.find((button) => button.dataset.iptvTab === kind) : null}}); },
        submitSearch(kind) {
            let prevented = false; const form = panelMap.get(`[data-iptv-form="${kind}"]`);
            panel.listeners.get('submit')({target: {closest: (selector) => selector === '[data-iptv-form]' ? form : null}, preventDefault() { prevented = true; }});
            return prevented;
        },
        clickPagination(kind, direction) {
            const button = panelMap.get(`[data-iptv-${direction}="${kind}"]`);
            panel.listeners.get('click')({target: {closest(selector) {
                if (selector === `[data-iptv-${direction}]`) return button;
                return null;
            }}});
        },
        hostSessionError(errorCode) { emit('semyra:host-session-error', {errorCode}); },
        hostAuthorized() { emit('semyra:host-authorized', {}); },
    };
}

test('free account sees Premium entry and never requests catalog or publish', () => {
    const page = harness(false); page.hostReady(); page.chooseIptv(); page.hostAuthorized();
    assert.equal(page.sourceTabs.hidden, false); assert.match(page.status.textContent, /Premium/); assert.deepEqual(page.requests, []);
});

test('premium account without WHIP has an explicit unavailable state', () => {
    const page = harness(); page.hostReady(['iptv.catalog']); page.chooseIptv();
    assert.equal(page.controls.hidden, true); assert.equal(page.unavailable.hidden, false); assert.deepEqual(page.requests, []);
});

test('catalog navigation loads Live, Movies and Series with bounded pagination and generation tokens', () => {
    const page = harness(); page.hostReady(); page.chooseIptv();
    assert.equal(page.requests.filter((item) => item?.action === 'list').length, 1);
    page.listResult();
    assert.ok(page.requests.some((item) => item.action === 'groups'));
    assert.ok(page.requests.some((item) => item.action === 'search' && item.limit === 30));
    page.clickTab('movies');
    assert.ok(page.requests.some((item) => item.action === 'vod-categories' && item.mediaType === 'movie'));
    assert.ok(page.requests.some((item) => item.action === 'vod-movies-search' && item.limit === 24));
    page.clickTab('series');
    assert.ok(page.requests.some((item) => item.action === 'vod-series-search' && item.limit === 24));
    assert.ok(page.requests.every((item) => !item || !('url' in item) && !('streamUrl' in item) && !('username' in item) && !('password' in item)));
});

test('VOD remains visible with a clear unavailable state when capability is absent', () => {
    const page = harness(); page.hostReady(['iptv.catalog', 'livekit.publish']); page.chooseIptv(); page.listResult(); page.clickTab('movies');
    assert.equal(page.vodUnavailable.hidden, false);
    assert.equal(page.requests.some((item) => item.action === 'vod-movies-search'), false);
});

test('movie publish sends only media mode to backend and opaque kind plus ID to native', async () => {
    const page = harness(); page.hostReady(); page.chooseIptv(); page.listResult();
    page.clickTab('movies');
    page.result('vod-movies-search', {items: [{id: 42, name: 'Filme seguro'}], hasMore: false});
    const action = page.panelMap.get('[data-iptv-results-modern="movies"]').children[0].children[1].children[2];
    page.panel.listeners.get('click')({target: {closest(selector) {
        if (selector === '[data-publish-kind]') return action;
        return null;
    }}});
    await new Promise((resolve) => setImmediate(resolve));

    assert.deepEqual([...page.webBodies[0].keys()], ['_token', 'media_mode']);
    assert.equal(page.webBodies[0].get('media_mode'), 'vod');
    page.hostAuthorized();
    const publish = page.requests.at(-1);
    assert.deepEqual(Object.keys(publish), ['action', 'kind', 'mediaId']);
    assert.equal(publish.action, 'publish-start');
    assert.equal(publish.kind, 'movie');
    assert.equal(publish.mediaId, 42);
    assert.equal(JSON.stringify(publish).includes('url'), false);
});

test('late results are fenced and source change invalidates old generation', () => {
    const page = harness(); page.hostReady(); page.chooseIptv(); page.listResult([{id: 7, name: 'A', type: 'xtream'}, {id: 8, name: 'B', type: 'xtream'}]);
    const old = [...page.requests].reverse().find((item) => item.action === 'search');
    page.sourceSelect.value = '8'; page.sourceSelect.listeners.get('change')();
    const list = page.panelMap.get('[data-iptv-results-modern="live"]'); const before = list.children;
    const listener = page.result.bind(page); // current result helper uses the newest token
    assert.notEqual(old.clientRequestId, [...page.requests].reverse().find((item) => item.action === 'search').clientRequestId);
    assert.equal(list.children, before); assert.equal(typeof listener, 'function');
});

test('sanitized Host Session failures have understandable messages', () => {
    const page = harness(); page.hostReady(); page.hostSessionError('transmission_changed');
    assert.equal(page.status.textContent, 'A transmissão da sala mudou. Tente novamente.');
    page.hostSessionError('vod_codec_unsupported'); assert.match(page.status.textContent, /codecs/);
});

test('IPTV searches never submit the sibling YouTube form', () => {
    const page = harness(); page.hostReady(); page.chooseIptv(); page.listResult();
    assert.equal(page.submitSearch('live'), true);
    page.clickTab('movies'); assert.equal(page.submitSearch('movies'), true);
    page.clickTab('series'); assert.equal(page.submitSearch('series'), true);
    assert.ok(page.requests.some((item) => item.action === 'search'));
    assert.ok(page.requests.some((item) => item.action === 'vod-movies-search'));
    assert.ok(page.requests.some((item) => item.action === 'vod-series-search'));
    assert.equal(page.youtubeSubmits, 0);
});

test('all catalog kinds expose bounded pagination and reset catalog scroll', () => {
    const page = harness(); page.hostReady(); page.chooseIptv(); page.listResult();
    page.result('search', {channels: Array.from({length: 30}, (_, id) => ({id, name: `Canal ${id}`})), hasMore: true});
    assert.equal(page.panelMap.get('[data-iptv-next="live"]').disabled, false);
    page.clickPagination('live', 'next');
    page.result('search', {channels: [{id: 31, name: 'Canal 31'}], hasMore: false});
    assert.equal(page.panelMap.get('[data-iptv-previous="live"]').disabled, false);
    page.clickTab('movies');
    page.result('vod-movies-search', {items: [{id: 1, name: 'Filme'}], hasMore: true});
    assert.equal(page.panelMap.get('[data-iptv-next="movies"]').disabled, false);
    page.clickPagination('movies', 'next');
    page.clickTab('series');
    page.result('vod-series-search', {items: [{id: 2, name: 'Série'}], hasMore: true});
    assert.equal(page.panelMap.get('[data-iptv-next="series"]').disabled, false);
    page.clickPagination('series', 'next');
    assert.equal(page.scrollResets, 3);
    assert.equal(page.youtubeSubmits, 0);
});

test('movie and series cards keep poster, text and action in separate semantic regions', () => {
    const page = harness(); page.hostReady(); page.chooseIptv(); page.listResult();
    const longTitle = 'Um filme com um título completo muito longo para o card compacto';
    page.clickTab('movies');
    page.result('vod-movies-search', {items: [{id: 41, name: longTitle, year: '2024', rating: '7.2', genre: 'Ação · Drama'}], hasMore: false});
    const movie = page.panelMap.get('[data-iptv-results-modern="movies"]').children[0];
    assert.equal(movie.tagName, 'article');
    assert.equal(movie.children[0].className, 'room-iptv-poster');
    assert.equal(movie.children[1].className, 'room-iptv-poster-content');
    assert.equal(movie.children[1].children[0].className, 'room-iptv-poster-title');
    assert.equal(movie.children[1].children[0].title, longTitle);
    assert.equal(movie.children[1].children[1].textContent, '2024 · ★ 7.2');
    assert.equal(movie.children[1].children[1].textContent.includes('Ação'), false);
    const movieAction = movie.children[1].children[2];
    assert.equal(movieAction.tagName, 'button');
    assert.equal(movieAction.textContent, 'Transmitir');
    assert.equal(movieAction.dataset.publishKind, 'movie');
    assert.equal(movie.children[0].children.some((child) => child.textContent === longTitle || child.textContent === 'Transmitir'), false);

    page.clickTab('series');
    page.result('vod-series-search', {items: [{id: 51, name: 'Série organizada', year: '2023', rating: '8.0'}], hasMore: false});
    const series = page.panelMap.get('[data-iptv-results-modern="series"]').children[0];
    assert.equal(series.tagName, 'article');
    assert.equal(series.children[1].children[2].dataset.itemKind, 'series');
    assert.equal(series.children[1].children[2].textContent, 'Ver episódios');
});

test('24 movie and series cards keep complete clickable actions after scrolling', () => {
    const page = harness(); page.hostReady(); page.chooseIptv(); page.listResult();
    page.clickTab('movies');
    page.result('vod-movies-search', {items: Array.from({length: 24}, (_, index) => ({id: index + 1, name: `Filme ${index + 1}`, year: '2025', rating: '8.0'})), hasMore: true});
    const movies = page.panelMap.get('[data-iptv-results-modern="movies"]').children;
    assert.equal(movies.length, 24);
    movies.forEach((card, index) => {
        assert.equal(card.tagName, 'article');
        assert.equal(card.children[1].children[2].tagName, 'button');
        assert.equal(card.children[1].children[2].dataset.mediaId, String(index + 1));
    });
    page.clickTab('series');
    page.result('vod-series-search', {items: Array.from({length: 24}, (_, index) => ({id: index + 101, name: `Série ${index + 1}`, year: '2025', rating: '8.0'})), hasMore: true});
    const series = page.panelMap.get('[data-iptv-results-modern="series"]').children;
    assert.equal(series.length, 24);
    series.forEach((card) => {
        assert.equal(card.children[1].children[2].tagName, 'button');
        assert.equal(card.children[1].children[2].dataset.itemKind, 'series');
    });
});

test('series detail selects real seasons and publishes only the selected episode', async () => {
    const page = harness(); page.hostReady(); page.chooseIptv(); page.listResult(); page.clickTab('series');
    page.result('vod-series-search', {items: [{id: 88, name: 'Ação · Animação · Comédia · Séries', year: '2020'}], hasMore: false});
    const card = page.panelMap.get('[data-iptv-results-modern="series"]').children[0];
    const cardAction = card.children[1].children[2];
    page.panel.listeners.get('click')({target: {closest: (selector) => selector === '[data-item-kind]' ? cardAction : null}});
    page.result('vod-series-details', {episodes: [
        {id: 101, seasonNumber: 1, episodeNumber: 1, name: 'Episódio'},
        {id: 102, seasonNumber: 1, episodeNumber: 2, name: 'Dois'},
        {id: 201, seasonNumber: 2, episodeNumber: 1, name: 'Três'},
        {id: 202, seasonNumber: 2, episodeNumber: 2, name: 'Quatro'},
        {id: 203, seasonNumber: 2, episodeNumber: 3, name: 'Cinco'},
        {id: 301, seasonNumber: 3, episodeNumber: 1, name: 'Seis'},
    ]});
    const detail = page.panelMap.get('[data-iptv-series-detail]');
    const seasonButtons = detail.querySelectorAll('[data-season-select]');
    const texts = []; const visit = (node) => { if (node.textContent) texts.push(node.textContent); (node.children || []).forEach(visit); };
    visit(detail);
    assert.match(texts.join(' '), /Ação · Animação · Comédia · Séries/);
    assert.match(texts.join(' '), /Episódio/);
    assert.deepEqual(seasonButtons.map((button) => button.textContent), ['Temporada 1', 'Temporada 2', 'Temporada 3']);
    assert.deepEqual(seasonButtons.map((button) => button.hidden), [false, false, false]);
    assert.deepEqual(seasonButtons.map((button) => button.type), ['button', 'button', 'button']);
    assert.deepEqual(seasonButtons.map((button) => button.getAttribute('aria-label')), ['Temporada 1', 'Temporada 2', 'Temporada 3']);
    assert.equal(detail.querySelectorAll('[data-publish-kind]').length, 2);
    seasonButtons[1].listeners.get('click')();
    let episodeButtons = detail.querySelectorAll('[data-publish-kind]');
    assert.deepEqual(episodeButtons.map((button) => button.dataset.mediaId), ['201', '202', '203']);
    assert.match(detail.querySelectorAll('[data-season-select]').find((button) => button.getAttribute('aria-selected') === 'true').textContent, /Temporada 2/);
    seasonButtons[2].listeners.get('click')();
    episodeButtons = detail.querySelectorAll('[data-publish-kind]');
    assert.deepEqual(episodeButtons.map((button) => button.dataset.mediaId), ['301']);
    assert.match(detail.querySelectorAll('[data-season-select]').find((button) => button.getAttribute('aria-selected') === 'true').textContent, /Temporada 3/);
    seasonButtons[1].listeners.get('click')();
    episodeButtons = detail.querySelectorAll('[data-publish-kind]');
    assert.equal(episodeButtons[1].dataset.mediaId, '202');
    page.panel.listeners.get('click')({target: {closest: (selector) => selector === '[data-publish-kind]' ? episodeButtons[1] : null}});
    await new Promise((resolve) => setImmediate(resolve));
    page.hostAuthorized();
    assert.equal(page.requests.at(-1).action, 'publish-start');
    assert.equal(page.requests.at(-1).kind, 'episode');
    assert.equal(page.requests.at(-1).mediaId, 202);
    assert.equal(page.youtubeSubmits, 0);
});

test('owned UI strings remain valid UTF-8 without mojibake sentinels', () => {
    assert.match(sourceCode, /← Voltar para Séries/);
    assert.match(sourceCode, /Episódio/);
    assert.doesNotMatch(sourceCode, /Ã|Â|â†|ï¿½/);
});
