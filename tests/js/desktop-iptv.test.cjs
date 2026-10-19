'use strict';

const assert = require('node:assert/strict');
const iptv = require('../../public/assets/js/desktop-iptv.js');

assert.equal(typeof iptv.createIptvCatalog, 'function');
assert.equal(iptv.mediaStatusLabel('preparing'), 'Preparando canal…');
assert.equal(iptv.mediaStatusLabel('streaming'), 'Reproduzindo agora');
assert.equal(iptv.mediaStatusLabel('playing'), 'Reproduzindo no MPV');
assert.equal(iptv.mediaStatusLabel('buffering'), 'Armazenando dados...');
assert.equal(iptv.mediaStatusLabel('reconnecting'), 'Reconectando…');
assert.equal(iptv.mediaStatusLabel('failed', 'media_runtime_unavailable'), 'Player local indisponível neste dispositivo.');
assert.equal(iptv.mediaStatusLabel('failed', 'invalid_mpegts'), 'Não foi possível reproduzir este conteúdo.');
assert.equal(iptv.channelMonogram('Globo News'), 'GN');
assert.equal(iptv.channelMonogram('Band'), 'BA');
assert.equal(iptv.channelMonogram(''), 'TV');
assert.equal(iptv.shouldRenderRemoteLogo('https://provider.example/logo.png?token=secret'), false);

assert.equal(iptv.localViewAvailable({capabilities: []}), false);
assert.equal(iptv.localViewAvailable({capabilities: ['iptv.local-view']}), true);
assert.equal(iptv.formatMediaTime(65), '1:05');
assert.equal(iptv.formatMediaTime(3661), '1:01:01');
const rangeStyle = new Map();
const controlledRange = {value: '', dataset: {}, style: {setProperty: (name, value) => rangeStyle.set(name, value)}};
assert.equal(iptv.syncRangeVisual(controlledRange, 35), 35);
assert.equal(controlledRange.value, '35');
assert.equal(controlledRange.dataset.rangeProgress, '35');
assert.equal(rangeStyle.get('--iptv-range-progress'), '35%');
assert.equal(iptv.syncRangeVisual(controlledRange, 140), 100);
assert.equal(controlledRange.value, '100');

class FakeClassList {
    constructor(owner) { this.owner = owner; }
    toggle(name, force) {
        const names = new Set(this.owner.className.split(/\s+/).filter(Boolean));
        if (force) names.add(name); else names.delete(name);
        this.owner.className = [...names].join(' ');
    }
    add(...values) { values.forEach((value) => this.toggle(value, true)); }
    remove(...values) { values.forEach((value) => this.toggle(value, false)); }
    contains(value) { return this.owner.className.split(/\s+/).includes(value); }
}

class FakeElement {
    constructor(tag = 'div') {
        this.tagName = tag.toUpperCase();
        this.className = '';
        this.classList = new FakeClassList(this);
        this.dataset = {};
        this.children = [];
        this.listeners = new Map();
        this.attributes = new Map();
        this.hidden = false;
        this.disabled = false;
        this.open = false;
        this.textContent = '';
        this.value = '';
        this.title = '';
        this.styleValues = new Map();
        this.style = {setProperty: (name, value) => this.styleValues.set(name, value)};
        this.replaceCount = 0;
    }
    appendChild(child) {
        if (child.isFragment) {
            child.children.slice().forEach((item) => this.appendChild(item));
            child.children = [];
            return child;
        }
        child.parent = this;
        this.children.push(child);
        return child;
    }
    replaceChildren(...children) { this.replaceCount += 1; this.children = []; children.forEach((child) => this.appendChild(child)); }
    addEventListener(type, listener) { this.listeners.set(type, listener); }
    emit(type, event = {}) { this.listeners.get(type)?.(Object.assign({currentTarget: this, target: this, preventDefault() {}}, event)); }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    closest(selector) {
        if (selector === '[data-iptv-view-start]' && this.dataset.iptvViewStart) return this;
        if (selector === '[data-iptv-favorite]' && this.dataset.iptvFavorite) return this;
        if (selector === '[data-iptv-recent-remove]' && this.dataset.iptvRecentRemove) return this;
        if (selector === '[data-iptv-view]' && this.dataset.iptvView) return this;
        if (selector === '[data-iptv-action]' && this.dataset.iptvAction) return this;
        if (selector === '[data-source-id]' && this.dataset.sourceId) return this;
        if (selector === '.iptv-channel-card' && this.className.split(/\s+/).includes('iptv-channel-card')) return this;
        return this.parent?.closest(selector) || null;
    }
    querySelector(selector) {
        if (selector === '.iptv-channel-copy strong') return find(this, (item) => item.tagName === 'STRONG' && item.parent?.className === 'iptv-channel-copy');
        if (selector === '[data-iptv-channel-live]') return find(this, (item) => Object.hasOwn(item.dataset, 'iptvChannelLive'));
        if (selector === 'button[type="submit"]') return find(this, (item) => item.tagName === 'BUTTON' && item.type === 'submit');
        return null;
    }
    querySelectorAll(selector) {
        const tags = selector.split(',').map((item) => item.trim().toUpperCase());
        const matches = [];
        function walk(item) {
            item.children.forEach((child) => {
                if (tags.includes(child.tagName)) matches.push(child);
                walk(child);
            });
        }
        walk(this);
        return matches;
    }
    contains(candidate) { return candidate === this || Boolean(find(this, (item) => item === candidate)); }
    scrollIntoView() {}
    focus() { fakeDocument.activeElement = this; }
    showModal() { this.open = true; }
    close() { this.open = false; }
}

function find(root, predicate) {
    for (const child of root.children) {
        if (predicate(child)) return child;
        const nested = find(child, predicate);
        if (nested) return nested;
    }
    return null;
}

const rootListeners = new Map();
const documentListeners = new Map();
const requests = [];
const confirmations = [];
let confirmationResult = true;
const registry = new Map();
const root = new FakeElement('section');
root.querySelector = (selector) => registry.get(selector) || null;
root.querySelectorAll = (selector) => {
    if (selector === '[data-iptv-native-vod]') {
        return [
            registry.get('[data-iptv-native-progress]'),
            registry.get('[data-iptv-native-playback]'),
            registry.get('[data-iptv-native-seek="-30"]'),
            registry.get('[data-iptv-native-seek="30"]'),
        ].filter(Boolean);
    }
    const container = selector === '[data-iptv-view]'
        ? registry.get('[data-iptv-view-tabs]')
        : registry.get('[data-iptv-channel-list]');
    const matches = [];
    function walk(item) {
        item?.children?.forEach((child) => {
            if (selector === '[data-iptv-view-start]' && child.dataset.iptvViewStart) matches.push(child);
            if (selector === '[data-iptv-favorite]' && child.dataset.iptvFavorite) matches.push(child);
            if (selector === '[data-iptv-recent-remove]' && child.dataset.iptvRecentRemove) matches.push(child);
            if (selector === '[data-iptv-view]' && child.dataset.iptvView) matches.push(child);
            walk(child);
        });
    }
    walk(container);
    return matches;
};

function register(selector, element = new FakeElement()) { registry.set(selector, element); return element; }
const form = register('[data-iptv-search-form]', new FakeElement('form'));
const query = new FakeElement('input'); query.value = '';
const group = new FakeElement('select'); group.value = '';
const searchSubmit = new FakeElement('button'); searchSubmit.type = 'submit'; form.appendChild(searchSubmit);
form.elements = {query, group};
registry.set('[data-iptv-search-form] input[name="query"]', query);
registry.set('[data-iptv-search-form] select[name="group"]', group);
const urlForm = register('[data-iptv-url-form]', new FakeElement('form'));
urlForm.elements = {name: {value: 'Lista'}, location: {value: 'https://private.invalid/list'}};
const addUrlButton = new FakeElement('button'); addUrlButton.type = 'submit'; addUrlButton.textContent = 'Adicionar URL'; urlForm.appendChild(addUrlButton);
const xtreamForm = register('[data-iptv-xtream-form]', new FakeElement('form'));
xtreamForm.elements = {
    name: {value: 'Minha Xtream'},
    baseUrl: {value: 'https://provider.example:8443/'},
    username: {value: 'private-user'},
    password: {value: 'private-pass'},
};
const addXtreamButton = new FakeElement('button'); addXtreamButton.type = 'submit'; addXtreamButton.textContent = 'Adicionar Xtream'; xtreamForm.appendChild(addXtreamButton);
const stage = register('[data-iptv-player-stage]');
stage.getBoundingClientRect = () => ({left: 20, top: 40, width: 960, height: 540});
const settings = register('[data-iptv-settings]', new FakeElement('dialog'));
const settingsContent = register('[data-iptv-settings-content]', new FakeElement('div'));
const settingsLoader = register('[data-iptv-settings-loader]', new FakeElement('div'));
const viewTabs = register('[data-iptv-view-tabs]', new FakeElement('div'));
for (const view of ['favorites', 'recents', 'all']) {
    const tab = new FakeElement('button');
    tab.dataset.iptvView = view;
    viewTabs.appendChild(tab);
    registry.set('[data-iptv-view="' + view + '"]', tab);
}
register('[data-iptv-view-panel]', new FakeElement('div'));
register('[data-iptv-channels-title]', new FakeElement('h2'));
register('[data-iptv-pagination]', new FakeElement('div'));
register('[data-iptv-settings-loader-title]', new FakeElement('strong'));
register('[data-iptv-settings-loader-copy]', new FakeElement('p'));
const channels = register('[data-iptv-channel-list]');
for (const selector of [
    '[data-iptv-browser-notice]', '[data-iptv-desktop-panel]', '[data-iptv-source-loading]', '[data-iptv-onboarding]', '[data-iptv-library]',
    '[data-iptv-source-switcher]', '[data-iptv-current-source]', '[data-iptv-source-select-wrap]', '[data-iptv-source-select]',
    '[data-iptv-settings-open]', '[data-iptv-onboarding-open]', '[data-iptv-settings-close]', '[data-iptv-pick-file]',
    '[data-iptv-source-list]', '[data-iptv-feedback]', '[data-iptv-player-empty]', '[data-iptv-player-state]',
    '[data-iptv-player-state-label]', '[data-iptv-player-error]', '[data-iptv-retry]',
    '[data-iptv-error-title]', '[data-iptv-error-copy]',
    '[data-iptv-choose-channel]',
    '[data-iptv-previous]', '[data-iptv-next]', '[data-iptv-page-label]', '[data-iptv-result-count]',
    '[data-iptv-native-controls]', '[data-iptv-native-playback]', '[data-iptv-native-seek="-30"]',
    '[data-iptv-native-seek="30"]', '[data-iptv-native-mute]', '[data-iptv-native-volume]', '[data-iptv-native-stop]',
    '[data-iptv-native-timeline]', '[data-iptv-native-progress]', '[data-iptv-native-fullscreen]',
    '[data-iptv-native-context]', '[data-iptv-native-title]', '[data-iptv-native-buffering]',
    '[data-iptv-native-fullscreen-icon]', '[data-iptv-native-compress-icon]',
    '[data-iptv-recents-actions]', '[data-iptv-recents-clear]', '[data-iptv-recents-confirm]',
    '[data-iptv-recents-cancel]', '[data-iptv-recents-confirm-clear]', '[data-iptv-recents-feedback]',
]) if (!registry.has(selector)) register(selector);
registry.get('[data-iptv-pick-file]').tagName = 'BUTTON';
settingsContent.appendChild(urlForm);
settingsContent.appendChild(xtreamForm);
settingsContent.appendChild(registry.get('[data-iptv-pick-file]'));
settingsContent.appendChild(registry.get('[data-iptv-source-list]'));

const logoutForm = new FakeElement('form');
const fakeDocument = {
    activeElement: null,
    documentElement: new FakeElement('html'),
    body: new FakeElement('body'),
    createElement: (tag) => new FakeElement(tag),
    createElementNS: (_, tag) => new FakeElement(tag),
    createDocumentFragment: () => Object.assign(new FakeElement('fragment'), {isFragment: true}),
    querySelector: (selector) => selector === '[data-iptv-catalog]' ? root : selector === 'form.account-logout[action="/logout"]' ? logoutForm : null,
    addEventListener: (type, listener) => documentListeners.set(type, listener),
};
const lifecycleTarget = {
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    dispatchEvent: (event) => requests.push(event.detail),
    addEventListener: (type, listener) => rootListeners.set(type, listener),
    innerWidth: 1200,
    innerHeight: 800,
    scrollX: 10,
    scrollY: 120,
    scrollTo: (x, y) => { lifecycleTarget.restoredScroll = [x, y]; },
    pendingTimers: [],
    nextTimerId: 0,
    setTimeout: (callback, delay) => {
        callback.testDelay = delay;
        callback.timerId = ++lifecycleTarget.nextTimerId;
        lifecycleTarget.pendingTimers.push(callback);
        return callback.timerId;
    },
    clearTimeout: (timerId) => {
        const index = lifecycleTarget.pendingTimers.findIndex((callback) => callback.timerId === timerId);
        if (index >= 0) lifecycleTarget.pendingTimers.splice(index, 1);
    },
    ResizeObserver: class {
        constructor(callback) { lifecycleTarget.resizeCallback = callback; }
        observe() {}
        disconnect() { lifecycleTarget.resizeDisconnected = true; }
    },
    IntersectionObserver: class {
        constructor(callback) { lifecycleTarget.intersectionCallback = callback; }
        observe() {}
        disconnect() { lifecycleTarget.intersectionDisconnected = true; }
    },
    confirm: (message) => { confirmations.push(message); return confirmationResult; },
    document: fakeDocument,
};
const isAutoHideTimer = (callback) => callback.testDelay > 2900 && callback.testDelay <= 3000;

assert.equal(iptv.createIptvCatalog(lifecycleTarget).start(), true);

function assertNativeControlIcon(selector, name) {
    const button = registry.get(selector);
    assert.equal(button.children.length, 1, selector + ' must contain exactly one SVG');
    assert.equal(button.children[0].tagName, 'SVG');
    assert.equal(button.children[0].dataset.playerIcon, name);
    assert.equal(button.children[0].children.length, 1);
    assert.equal(button.children[0].children[0].attributes.get('href'), '#iptv-icon-' + name);
}
assert.equal(registry.get('[data-iptv-source-loading]').hidden, false);
rootListeners.get('semyra:host-ready')({detail: {capabilities: ['iptv.sources', 'iptv.catalog', 'iptv.local-view']}});
assert.deepEqual(requests.slice(-2).map((item) => item.action), ['list', 'view-status']);
const initialListToken = requests.at(-2).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'list', ok: true, clientRequestId: initialListToken, sources: []}});
assert.equal(registry.get('[data-iptv-source-loading]').hidden, true);
assert.equal(registry.get('[data-iptv-onboarding]').hidden, false);
assert.equal(registry.get('[data-iptv-library]').hidden, true);
registry.get('[data-iptv-onboarding-open]').emit('click');
assert.equal(settings.open, true);
registry.get('[data-iptv-settings-close]').emit('click');
assert.equal(settings.open, false);

rootListeners.get('semyra:iptv-result')({detail: {action: 'list', ok: true, clientRequestId: initialListToken, sources: [
    {id: 1, name: 'Casa', type: 'm3u_file', channelCount: 2, lastRefreshStatus: 'ready'},
    {id: 2, name: 'Viagem', type: 'm3u_url', channelCount: 1, lastRefreshStatus: 'ready'},
]}});
assert.equal(registry.get('[data-iptv-onboarding]').hidden, true);
assert.equal(registry.get('[data-iptv-library]').hidden, false);
assert.equal(registry.get('[data-iptv-source-select-wrap]').hidden, false);
assert.equal(registry.get('[data-iptv-source-select]').children.length, 2);
assert.deepEqual(requests.slice(-2).map((item) => item.action), ['groups', 'search']);
const initialGroupsToken = requests.at(-2).clientRequestId;
const initialSearchToken = requests.at(-1).clientRequestId;
assert.equal(channels.attributes.get('aria-busy'), 'true');
assert.equal(channels.children.length, 10);

rootListeners.get('semyra:iptv-result')({detail: {action: 'groups', ok: true, clientRequestId: initialGroupsToken, groups: ['Notícias', 'Esportes']}});
assert.equal(group.children.length, 3);
rootListeners.get('semyra:iptv-result')({detail: {action: 'search', ok: true, clientRequestId: initialSearchToken, offset: 0, hasMore: true, channels: [
    {id: 7, name: 'Globo News', groupName: 'Notícias', logoUrl: 'https://unsafe.invalid/logo?token=secret'},
]}});
assert.equal(channels.children.length, 1);
assert.equal(channels.attributes.get('aria-busy'), 'false');
let playableCard = channels.children[0];
const sourceList = registry.get('[data-iptv-source-list]');
const firstSource = sourceList.children[0];
const secondSource = sourceList.children[1];
assert.equal(find(firstSource, (item) => item.className === 'iptv-source-current').textContent, 'Em uso');
assert.equal(find(firstSource, (item) => item.dataset.iptvAction === 'select'), null);
assert.equal(find(secondSource, (item) => item.dataset.iptvAction === 'select').textContent, 'Selecionar');
assert.equal(find(sourceList, (item) => item.textContent === 'Usar'), null);
assert.equal(find(sourceList, (item) => item.dataset.iptvAction === 'vod-refresh'), null, 'M3U sources must not expose VOD sync');

registry.get('[data-iptv-settings-open]').emit('click');
assert.equal(settings.dataset.settingsState || 'idle', 'idle');
const refreshButton = find(secondSource, (item) => item.dataset.iptvAction === 'refresh');
sourceList.emit('click', {target: refreshButton});
const refreshToken = requests.at(-1).clientRequestId;
assert.equal(settings.dataset.settingsState, 'refreshing-source');
assert.equal(settings.attributes.get('aria-busy'), 'true');
assert.equal(settingsLoader.hidden, false);
assert.equal(registry.get('[data-iptv-settings-loader-title]').textContent, 'Atualizando catálogo…');
assert.equal(settingsContent.attributes.has('inert'), true);
assert.equal(registry.get('[data-iptv-settings-close]').disabled, true);
assert.equal(registry.get('[data-iptv-pick-file]').disabled, true);
assert.equal(addUrlButton.disabled, true);
const requestsWhileBusy = requests.length;
sourceList.emit('click', {target: find(firstSource, (item) => item.dataset.iptvAction === 'remove')});
assert.equal(requests.length, requestsWhileBusy, 'second settings action must be ignored while busy');
registry.get('[data-iptv-settings-close]').emit('click');
assert.equal(settings.open, true, 'close button must not dismiss a busy dialog');
let escapePrevented = 0;
settings.emit('cancel', {preventDefault: () => { escapePrevented += 1; }});
assert.equal(escapePrevented, 1, 'Escape must be blocked while busy');
rootListeners.get('semyra:iptv-result')({detail: {action: 'refresh', ok: true, clientRequestId: refreshToken, source: {
    id: 2, name: 'Viagem', type: 'm3u_url', channelCount: 4, lastRefreshStatus: 'ready',
}}});
assert.equal(settings.dataset.settingsState, 'idle');
assert.equal(settingsLoader.hidden, true);
assert.equal(settingsContent.attributes.has('inert'), false);
assert.equal(registry.get('[data-iptv-settings-close]').disabled, false);
assert.equal(registry.get('[data-iptv-feedback]').textContent, '✓ Catálogo atualizado.');
assert.equal(registry.get('[data-iptv-source-select]').value, '1', 'refreshing an inactive source must not select it');

urlForm.emit('submit');
const failedAddToken = requests.at(-1).clientRequestId;
assert.equal(settings.dataset.settingsState, 'adding-url');
assert.equal(registry.get('[data-iptv-settings-loader-title]').textContent, 'Carregando sua IPTV…');
rootListeners.get('semyra:iptv-result')({detail: {action: 'add-url', ok: false, clientRequestId: failedAddToken, error: 'private_native_error'}});
assert.equal(settings.dataset.settingsState, 'idle', 'busy must clear after an error');
assert.equal(registry.get('[data-iptv-feedback]').textContent, 'Não foi possível carregar esta fonte.');

xtreamForm.emit('submit');
const failedXtreamToken = requests.at(-1).clientRequestId;
assert.equal(settings.dataset.settingsState, 'adding-xtream');
assert.deepEqual(requests.at(-1), {
    action: 'add-xtream', clientRequestId: failedXtreamToken,
    name: 'Minha Xtream', baseUrl: 'https://provider.example:8443/',
    username: 'private-user', password: 'private-pass',
});
assert.equal(xtreamForm.elements.password.value, '', 'password must be cleared after dispatch');
rootListeners.get('semyra:iptv-result')({detail: {action: 'add-xtream', ok: false, clientRequestId: failedXtreamToken, error: 'xtream_auth_failed'}});
assert.equal(settings.dataset.settingsState, 'idle');

registry.get('[data-iptv-pick-file]').emit('click');
const cancelledPickToken = requests.at(-1).clientRequestId;
assert.equal(settings.dataset.settingsState, 'adding-file');
rootListeners.get('semyra:iptv-result')({detail: {action: 'pick-file', ok: true, clientRequestId: cancelledPickToken, cancelled: true}});
assert.equal(settings.dataset.settingsState, 'idle', 'busy must clear when the file picker is cancelled');

urlForm.elements.location.value = 'https://private.invalid/new-list';
urlForm.emit('submit');
const addUrlToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'add-url', ok: true, clientRequestId: addUrlToken, source: {
    id: 3, name: 'Nova URL', type: 'm3u_url', channelCount: 0, lastRefreshStatus: 'never',
}}});
assert.equal(requests.at(-1).action, 'refresh', 'a new URL source must import automatically');
const newUrlRefreshToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'refresh', ok: true, clientRequestId: newUrlRefreshToken, source: {
    id: 3, name: 'Nova URL', type: 'm3u_url', channelCount: 31560, lastRefreshStatus: 'ready',
}}});
assert.deepEqual(requests.slice(-2).map((item) => item.action), ['groups', 'search']);
const newUrlGroupsToken = requests.at(-2).clientRequestId;
const newUrlSearchToken = requests.at(-1).clientRequestId;
assert.equal(settings.dataset.settingsState, 'adding-url');
rootListeners.get('semyra:iptv-result')({detail: {action: 'groups', ok: true, clientRequestId: newUrlGroupsToken, groups: ['Filmes']}});
rootListeners.get('semyra:iptv-result')({detail: {action: 'search', ok: true, clientRequestId: newUrlSearchToken, offset: 0, hasMore: false, channels: []}});
assert.equal(settings.open, false, 'new source modal closes only after catalog is ready');
assert.equal(registry.get('[data-iptv-source-select]').value, '3');

registry.get('[data-iptv-settings-open]').emit('click');
registry.get('[data-iptv-pick-file]').emit('click');
const pickToken = requests.at(-1).clientRequestId;
assert.equal(settings.dataset.settingsState, 'adding-file');
assert.equal(registry.get('[data-iptv-settings-loader-title]').textContent, 'Importando sua IPTV…');
rootListeners.get('semyra:iptv-result')({detail: {action: 'pick-file', ok: true, clientRequestId: pickToken, source: {
    id: 4, name: 'Arquivo Casa', type: 'm3u_file', channelCount: 0, lastRefreshStatus: 'never',
}}});
assert.equal(requests.at(-1).action, 'refresh', 'a new file source must import automatically');
const fileRefreshToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'refresh', ok: true, clientRequestId: fileRefreshToken, source: {
    id: 4, name: 'Arquivo Casa', type: 'm3u_file', channelCount: 12, lastRefreshStatus: 'ready',
}}});
const fileGroupsToken = requests.at(-2).clientRequestId;
const fileSearchToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'groups', ok: true, clientRequestId: fileGroupsToken, groups: []}});
rootListeners.get('semyra:iptv-result')({detail: {action: 'search', ok: true, clientRequestId: fileSearchToken, offset: 0, hasMore: false, channels: []}});
assert.equal(settings.open, false);
assert.equal(registry.get('[data-iptv-source-select]').value, '4');

registry.get('[data-iptv-settings-open]').emit('click');
const sourceOneCard = sourceList.children.find((item) => item.dataset.sourceId === '1');
const selectSourceOne = find(sourceOneCard, (item) => item.dataset.iptvAction === 'select');
sourceList.emit('click', {target: selectSourceOne});
assert.equal(settings.dataset.settingsState, 'selecting-source');
assert.equal(registry.get('[data-iptv-settings-loader-title]').textContent, 'Carregando canais…');
const selectionGroupsToken = requests.at(-2).clientRequestId;
const selectionSearchToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'groups', ok: true, clientRequestId: selectionGroupsToken, groups: []}});
rootListeners.get('semyra:iptv-result')({detail: {action: 'search', ok: true, clientRequestId: selectionSearchToken, offset: 0, hasMore: false, channels: []}});
assert.equal(settings.open, false, 'selecting a source closes after groups and channels are ready');
assert.equal(registry.get('[data-iptv-source-select]').value, '1');

const allTab = registry.get('[data-iptv-view="all"]');
const favoritesTab = registry.get('[data-iptv-view="favorites"]');
const recentsTab = registry.get('[data-iptv-view="recents"]');
assert.equal(allTab.attributes.get('aria-selected'), 'true', 'Todos must be the default view');
viewTabs.emit('click', {target: favoritesTab});
assert.equal(requests.at(-1).action, 'favorites');
const favoritesToken = requests.at(-1).clientRequestId;
assert.equal(channels.attributes.get('aria-busy'), 'true');
viewTabs.emit('keydown', {key: 'ArrowRight'});
assert.equal(recentsTab.attributes.get('aria-selected'), 'true', 'ArrowRight must move the active tab');
const recentsToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorites', ok: true, clientRequestId: favoritesToken, channels: [
    {id: 99, name: 'Resposta antiga', groupName: 'Stale', isFavorite: true},
]}});
assert.equal(channels.attributes.get('aria-busy'), 'true', 'stale tab response must not replace the current loading');
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents', ok: true, clientRequestId: recentsToken, channels: []}});
assert.match(find(channels, (item) => item.tagName === 'P').textContent, /ainda não assistiu/);

viewTabs.emit('click', {target: favoritesTab});
const failedFavoritesToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorites', ok: false, clientRequestId: failedFavoritesToken, error: 'desktop_bridge_timeout'}});
assert.equal(channels.attributes.get('aria-busy'), 'false');
assert.match(find(channels, (item) => item.tagName === 'P').textContent, /carregar os favoritos/);
viewTabs.emit('click', {target: recentsTab});
const failedRecentsToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents', ok: false, clientRequestId: failedRecentsToken, error: 'desktop_bridge_timeout'}});
assert.equal(channels.attributes.get('aria-busy'), 'false');
assert.match(find(channels, (item) => item.tagName === 'P').textContent, /carregar os recentes/);
viewTabs.emit('click', {target: favoritesTab});
const currentFavoritesToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorites', ok: true, clientRequestId: currentFavoritesToken, offset: 0, hasMore: false, channels: [
    {id: 7, name: 'Globo News', groupName: 'Notícias', isFavorite: true},
]}});
let favoriteCard = channels.children[0];
assert.equal(favoriteCard.tagName, 'ARTICLE');
assert.equal(favoriteCard.querySelectorAll('button').length, 2, 'play and favorite must be sibling buttons');
const favoritePlay = find(favoriteCard, (item) => item.dataset.iptvViewStart);
channels.emit('click', {target: favoritePlay});
assert.equal(requests.at(-1).action, 'view-start', 'a Favorite card must start local playback');
rootListeners.get('semyra:iptv-result')({detail: {action: 'view-start', ok: false, error: 'provider_connect_failed'}});
let favoriteButton = find(favoriteCard, (item) => item.dataset.iptvFavorite);
assert.equal(favoriteButton.attributes.get('aria-pressed'), 'true');
const playsBeforeFavorite = requests.filter((item) => item.action === 'view-start').length;
channels.emit('click', {target: favoriteButton});
assert.equal(requests.at(-1).action, 'favorite-set');
assert.equal(requests.at(-1).favorite, false);
assert.equal(requests.filter((item) => item.action === 'view-start').length, playsBeforeFavorite, 'favorite click must not start playback');
const removeFavoriteToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorite-set', ok: true, clientRequestId: removeFavoriteToken, channelId: 7, favorite: false}});
assert.equal(requests.at(-1).action, 'favorites', 'removing in Favorites must refresh the view');
const emptyFavoritesToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorites', ok: true, clientRequestId: emptyFavoritesToken, offset: 0, hasMore: false, channels: []}});
assert.match(find(channels, (item) => item.tagName === 'P').textContent, /favoritos aparecerão aqui/);

const sourceSelect = registry.get('[data-iptv-source-select]');
sourceSelect.value = '2';
sourceSelect.emit('change');
assert.deepEqual(requests.slice(-2).map((item) => item.action), ['groups', 'favorites'], 'source switch must preserve Favorites');
const sourceTwoGroupsToken = requests.at(-2).clientRequestId;
const sourceTwoFavoritesToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'groups', ok: true, clientRequestId: sourceTwoGroupsToken, groups: []}});
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorites', ok: true, clientRequestId: sourceTwoFavoritesToken, offset: 0, hasMore: false, channels: [
    {id: 22, name: 'Somente fonte dois', groupName: 'Teste', isFavorite: true},
]}});
assert.equal(find(channels.children[0], (item) => item.tagName === 'STRONG').textContent, 'Somente fonte dois');
sourceSelect.value = '1';
sourceSelect.emit('change');
assert.equal(requests.at(-1).action, 'favorites');
const sourceOneFavoritesToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorites', ok: true, clientRequestId: sourceOneFavoritesToken, offset: 0, hasMore: false, channels: []}});

registry.get('[data-iptv-settings-open]').emit('click');
const refreshedSourceCard = sourceList.children.find((item) => item.dataset.sourceId === '1');
sourceList.emit('click', {target: find(refreshedSourceCard, (item) => item.dataset.iptvAction === 'refresh')});
const activeRefreshToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'refresh', ok: true, clientRequestId: activeRefreshToken, source: {
    id: 1, name: 'Casa', type: 'm3u_file', channelCount: 2, lastRefreshStatus: 'ready',
}}});
assert.deepEqual(requests.slice(-2).map((item) => item.action), ['groups', 'favorites'], 'refresh must invalidate and reload the active Favorites view');
const refreshedGroupsToken = requests.at(-2).clientRequestId;
const refreshedFavoritesToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'groups', ok: true, clientRequestId: refreshedGroupsToken, groups: []}});
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorites', ok: true, clientRequestId: refreshedFavoritesToken, offset: 0, hasMore: false, channels: []}});
assert.equal(settings.dataset.settingsState, 'idle');
settings.close();

viewTabs.emit('click', {target: recentsTab});
const playableRecentsToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents', ok: true, clientRequestId: playableRecentsToken, channels: [
    {recentId: 17, id: 7, name: 'Globo News', groupName: 'Notícias', isFavorite: false},
]}});
assert.equal(registry.get('[data-iptv-recents-actions]').hidden, false);
assert.equal(root.querySelectorAll('[data-iptv-recent-remove]').length, 1, 'only Recents cards expose remove buttons');
let recentRemove = find(channels.children[0], (item) => item.dataset.iptvRecentRemove);
channels.emit('click', {target: recentRemove, stopPropagation() {}});
assert.equal(requests.at(-1).action, 'recent-remove');
assert.equal(requests.at(-1).sourceId, 1);
assert.equal(requests.at(-1).recentId, 17);
const failedRecentRemoveToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'recent-remove', ok: false, clientRequestId: failedRecentRemoveToken, error: 'recent_write_failed'}});
assert.equal(recentRemove.disabled, false, 'a failed removal must restore the card action');
assert.match(registry.get('[data-iptv-recents-feedback]').textContent, /remover este item/);
const recentPlay = find(channels.children[0], (item) => item.dataset.iptvViewStart);
channels.emit('click', {target: recentPlay});
assert.equal(requests.at(-1).action, 'view-start', 'a Recent card must start local playback');
rootListeners.get('semyra:iptv-result')({detail: {action: 'view-start', ok: false, error: 'provider_connect_failed'}});

viewTabs.emit('click', {target: allTab});
const liveReloadToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'search', ok: true, clientRequestId: liveReloadToken, offset: 0, hasMore: false, channels: [
    {id: 7, name: 'Globo News', groupName: 'Notícias', isFavorite: false},
]}});
playableCard = channels.children[0];
favoriteButton = find(playableCard, (item) => item.dataset.iptvFavorite);
channels.emit('click', {target: favoriteButton});
const addFavoriteToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorite-set', ok: true, clientRequestId: addFavoriteToken, channelId: 7, favorite: true}});
assert.equal(favoriteButton.attributes.get('aria-pressed'), 'true');
assert.equal(favoriteButton.attributes.get('aria-label'), 'Remover dos favoritos');
channels.emit('click', {target: favoriteButton});
const failedFavoriteToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorite-set', ok: false, clientRequestId: failedFavoriteToken, error: 'favorite_write_failed'}});
assert.equal(favoriteButton.attributes.get('aria-pressed'), 'true', 'favorite error must restore the confirmed state');
assert.equal(favoriteButton.disabled, false, 'favorite error must clear pending state');
assert.match(registry.get('[data-iptv-feedback]').textContent, /atualizar os favoritos/);

let card = playableCard;
let playButton = find(card, (item) => item.dataset.iptvViewStart);
assert.equal(card.tagName, 'ARTICLE');
assert.equal(playButton.tagName, 'BUTTON');
assert.equal(card.querySelectorAll?.('img')?.length || 0, 0);
channels.emit('click', {target: playButton});
assert.equal(requests.at(-1).action, 'view-start');
assert.equal(requests.at(-1).channelId, 7);
assert.equal(stage.dataset.state, 'preparing');

rootListeners.get('semyra:iptv-result')({detail: {
    action: 'view-start', ok: true, playbackKind: 'native-mpv', sessionId: 'b'.repeat(32), state: 'streaming', channelId: 7, channelName: 'Globo News', errorCode: null,
}});
assert.equal(stage.dataset.state, 'streaming');
assert.equal(registry.get('[data-iptv-player-empty]').hidden, true, 'idle content must not leak into streaming');
assert.equal(registry.get('[data-iptv-player-state]').hidden, true, 'preparing overlay must not leak into streaming');
viewTabs.emit('click', {target: favoritesTab});
const activeFavoritesToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'favorites', ok: true, clientRequestId: activeFavoritesToken, channels: [
    {id: 7, name: 'Globo News', groupName: 'Notícias', isFavorite: true},
]}});
assert.match(channels.children[0].className, /is-active/, 'active playback must be highlighted in Favorites');
viewTabs.emit('click', {target: recentsTab});
const activeRecentsToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents', ok: true, clientRequestId: activeRecentsToken, channels: [
    {recentId: 18, id: 7, name: 'Globo News', groupName: 'Notícias', isFavorite: true},
]}});
assert.match(channels.children[0].className, /is-active/, 'active playback must be highlighted in Recents');
recentRemove = find(channels.children[0], (item) => item.dataset.iptvRecentRemove);
channels.emit('click', {target: recentRemove, stopPropagation() {}});
const successfulRecentRemoveToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'recent-remove', ok: true, clientRequestId: successfulRecentRemoveToken, sourceId: 1, recentId: 18, removed: true}});
assert.equal(stage.dataset.state, 'streaming', 'removing a recent must not interrupt active playback');
assert.match(find(channels.children[0], (item) => item.tagName === 'P').textContent, /assistiu/, 'last removal must render the neutral empty state');
viewTabs.emit('click', {target: allTab});
viewTabs.emit('click', {target: recentsTab});
const clearableRecentsToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents', ok: true, clientRequestId: clearableRecentsToken, channels: [
    {recentId: 19, id: 7, name: 'Globo News', groupName: 'NotÃ­cias', isFavorite: true},
    {recentId: 20, id: 8, name: 'Filme', groupName: 'Filmes', isFavorite: false},
]}});
const requestsBeforeClearCancel = requests.length;
registry.get('[data-iptv-recents-clear]').emit('click');
assert.equal(requests.length, requestsBeforeClearCancel, 'first clear click must not call the bridge');
assert.equal(registry.get('[data-iptv-recents-confirm]').hidden, false, 'first clear click shows inline confirmation');
registry.get('[data-iptv-recents-cancel]').emit('click');
assert.equal(registry.get('[data-iptv-recents-confirm]').hidden, true, 'cancel hides inline confirmation');
assert.equal(requests.length, requestsBeforeClearCancel, 'cancelled clear must be a no-op');
registry.get('[data-iptv-recents-clear]').emit('click');
registry.get('[data-iptv-recents-confirm-clear]').emit('click');
const failedClearToken = requests.at(-1).clientRequestId;
assert.equal(requests.at(-1).action, 'recents-clear');
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents-clear', ok: false, clientRequestId: failedClearToken, error: 'recent_write_failed'}});
assert.equal(channels.children.length, 2, 'failed clear must preserve the current cards');
assert.match(registry.get('[data-iptv-recents-feedback]').textContent, /limpar os recentes/);
registry.get('[data-iptv-recents-confirm-clear]').emit('click');
const successfulClearToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents-clear', ok: true, clientRequestId: successfulClearToken, sourceId: 1, removedCount: 2}});
assert.equal(stage.dataset.state, 'streaming', 'clearing Recents must not interrupt active playback');
assert.match(find(channels.children[0], (item) => item.tagName === 'P').textContent, /assistiu/);
assert.equal(registry.get('[data-iptv-recents-actions]').hidden, true);
registry.get('[data-iptv-source-select]').value = '2';
registry.get('[data-iptv-source-select]').emit('change');
const sourceTwoRecentsToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents', ok: true, clientRequestId: sourceTwoRecentsToken, channels: [
    {recentId: 21, id: 22, name: 'Fonte dois', groupName: 'Teste', isFavorite: false},
]}});
registry.get('[data-iptv-recents-clear]').emit('click');
registry.get('[data-iptv-recents-confirm-clear]').emit('click');
const staleClearToken = requests.at(-1).clientRequestId;
registry.get('[data-iptv-source-select]').value = '1';
registry.get('[data-iptv-source-select]').emit('change');
const restoredSourceRecentsToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents', ok: true, clientRequestId: restoredSourceRecentsToken, channels: [
    {recentId: 22, id: 7, name: 'Fonte um preservada', groupName: 'Teste', isFavorite: false},
]}});
rootListeners.get('semyra:iptv-result')({detail: {action: 'recents-clear', ok: true, clientRequestId: staleClearToken, sourceId: 2, removedCount: 1}});
assert.equal(find(channels.children[0], (item) => item.tagName === 'STRONG').textContent, 'Fonte um preservada', 'stale clear response must not alter the new source');
viewTabs.emit('click', {target: allTab});
const activeLiveToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'search', ok: true, clientRequestId: activeLiveToken, offset: 0, hasMore: false, channels: [
    {id: 7, name: 'Globo News', groupName: 'Notícias', isFavorite: true},
]}});
card = channels.children[0];
playButton = find(card, (item) => item.dataset.iptvViewStart);
assert.match(card.className, /is-active/, 'active playback must remain highlighted in Ao vivo');
const startsBefore = requests.filter((item) => item.action === 'view-start').length;
channels.emit('click', {target: playButton});
assert.equal(requests.filter((item) => item.action === 'view-start').length, startsBefore);

rootListeners.get('semyra:iptv-media-state')({detail: {state: 'reconnecting', channelId: 7, channelName: 'Globo News'}});
assert.equal(stage.dataset.state, 'reconnecting');
rootListeners.get('semyra:iptv-media-state')({detail: {state: 'failed', channelId: 7, channelName: 'Globo News', errorCode: 'pipeline_exited'}});
assert.equal(registry.get('[data-iptv-player-error]').hidden, false);
registry.get('[data-iptv-retry]').emit('click');
assert.equal(requests.at(-1).action, 'view-start');

registry.get('[data-iptv-native-stop]').emit('click');
assert.equal(requests.at(-1).action, 'player-control');
assert.equal(stage.dataset.state, 'idle');
assert.equal(playButton.disabled, false);
const restartsBefore = requests.filter((item) => item.action === 'view-start').length;
channels.emit('click', {target: playButton});
assert.equal(requests.filter((item) => item.action === 'view-start').length, restartsBefore + 1, 'same channel must restart after idle');
rootListeners.get('semyra:iptv-result')({detail: {
    action: 'view-start', ok: true, playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'playing', channelId: 7, channelName: 'Globo News', errorCode: null, mediaKind: 'vod', position: 65, duration: 3600, paused: false, muted: false, volume: 35,
}});
assert.equal(stage.dataset.nativePlayer, 'true');
assert.equal(registry.get('[data-iptv-native-controls]').hidden, false);
assert.equal(registry.get('[data-iptv-native-timeline]').textContent, '1:05 / 1:00:00');
assert.equal(registry.get('[data-iptv-native-progress]').hidden, false);
assert.equal(registry.get('[data-iptv-native-playback]').hidden, false);
assert.equal(registry.get('[data-iptv-native-volume]').value, '35');
assert.equal(registry.get('[data-iptv-native-volume]').dataset.rangeProgress, '35');
assert.equal(registry.get('[data-iptv-native-volume]').styleValues.get('--iptv-range-progress'), '35%');
assertNativeControlIcon('[data-iptv-native-mute]', 'volume');
assert.equal(registry.get('[data-iptv-native-mute]').attributes.get('aria-label'), 'Silenciar');
lifecycleTarget.pendingTimers.splice(0).forEach((callback) => callback());
assert.deepEqual(requests.findLast((item) => item.action === 'player-geometry'), {
    action: 'player-geometry', sessionId: 'c'.repeat(32), x: 20, y: 40, width: 960, height: 540,
    viewportWidth: 1200, viewportHeight: 800, visible: true,
});
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'playing', channelId: 7,
    channelName: 'Globo News', mediaKind: 'live', position: 1, duration: 0, seekable: false,
}});
assert.equal(registry.get('[data-iptv-native-progress]').hidden, true);
assert.equal(registry.get('[data-iptv-native-playback]').hidden, true);
assert.equal(registry.get('[data-iptv-native-seek="30"]').hidden, true);
assert.equal(registry.get('[data-iptv-native-context]').textContent, 'Ao vivo');
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'playing', channelId: 7,
    channelName: 'Globo News', mediaKind: 'vod', position: 65, duration: 3600, seekable: true, paused: false,
}});
assert.equal(registry.get('[data-iptv-native-playback]').attributes.get('aria-label'), 'Pausar');
assertNativeControlIcon('[data-iptv-native-playback]', 'pause');
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'buffering', channelId: 7,
    channelName: 'Globo News', mediaKind: 'vod', position: 65, duration: 3600, seekable: true, paused: false,
}});
assert.equal(registry.get('[data-iptv-native-playback]').attributes.get('aria-label'), 'Pausar', 'buffering while unpaused keeps the playback intention');
assertNativeControlIcon('[data-iptv-native-playback]', 'pause');
registry.get('[data-iptv-native-playback]').emit('click');
assert.equal(requests.at(-1).command, 'pause');
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'paused', channelId: 7,
    channelName: 'Globo News', mediaKind: 'vod', position: 65, duration: 3600, seekable: true, paused: true,
}});
assert.equal(registry.get('[data-iptv-native-playback]').attributes.get('aria-label'), 'Reproduzir');
assertNativeControlIcon('[data-iptv-native-playback]', 'play');
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'buffering', channelId: 7,
    channelName: 'Globo News', mediaKind: 'vod', position: 65, duration: 3600, seekable: true, paused: true,
}});
assert.equal(registry.get('[data-iptv-native-playback]').attributes.get('aria-label'), 'Reproduzir', 'buffering preserves the confirmed pause intention');
for (let cycle = 0; cycle < 4; cycle += 1) {
    registry.get('[data-iptv-native-playback]').emit('click');
    assert.equal(requests.at(-1).command, 'play');
    rootListeners.get('semyra:iptv-media-state')({detail: {
        playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'playing', channelId: 7,
        channelName: 'Globo News', mediaKind: 'vod', position: 66 + cycle, duration: 3600, seekable: true, paused: false,
    }});
    assert.equal(registry.get('[data-iptv-native-playback]').attributes.get('aria-label'), 'Pausar');
    assertNativeControlIcon('[data-iptv-native-playback]', 'pause');
    if (cycle === 3) break;
    registry.get('[data-iptv-native-playback]').emit('click');
    assert.equal(requests.at(-1).command, 'pause');
    rootListeners.get('semyra:iptv-media-state')({detail: {
        playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'paused', channelId: 7,
        channelName: 'Globo News', mediaKind: 'vod', position: 66 + cycle, duration: 3600, seekable: true, paused: true,
    }});
    assert.equal(registry.get('[data-iptv-native-playback]').attributes.get('aria-label'), 'Reproduzir');
    assertNativeControlIcon('[data-iptv-native-playback]', 'play');
}
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'playing', channelId: 7,
    channelName: 'Globo News', mediaKind: 'vod', position: 66, duration: 3600, seekable: true,
    paused: false, muted: true, volume: 70,
}});
assert.equal(registry.get('[data-iptv-native-volume]').value, '70');
assert.equal(registry.get('[data-iptv-native-volume]').dataset.rangeProgress, '70');
assertNativeControlIcon('[data-iptv-native-mute]', 'muted');
assert.equal(registry.get('[data-iptv-native-mute]').attributes.get('aria-label'), 'Ativar som');
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'playing', channelId: 7,
    channelName: 'Globo News', mediaKind: 'vod', position: 66, duration: 3600, seekable: true,
    paused: false, muted: false, volume: 0,
}});
assertNativeControlIcon('[data-iptv-native-mute]', 'volume');
registry.get('[data-iptv-native-seek="30"]').emit('click');
assert.equal(requests.at(-1).command, 'seek-relative');
assert.equal(requests.at(-1).value, 30);
registry.get('[data-iptv-native-progress]').value = '90';
registry.get('[data-iptv-native-progress]').max = '3600';
registry.get('[data-iptv-native-progress]').emit('input');
registry.get('[data-iptv-native-progress]').emit('change');
assert.equal(requests.at(-1).command, 'seek-absolute');
assert.equal(requests.at(-1).value, 90);
lifecycleTarget.pendingTimers.splice(0).filter((callback) => callback.testDelay === 50).forEach((callback) => callback());
registry.get('[data-iptv-native-fullscreen]').emit('click');
assert.deepEqual(requests.at(-1), {action: 'player-fullscreen', sessionId: 'c'.repeat(32), fullscreen: true});
rootListeners.get('semyra:iptv-result')({detail: {
    action: 'player-fullscreen', ok: true, playbackKind: 'native-mpv', sessionId: 'c'.repeat(32),
    state: 'playing', channelId: 7, channelName: 'Globo News', mediaKind: 'vod', fullscreen: true,
}});
assert.match(root.className, /is-native-fullscreen/);
assert.match(fakeDocument.documentElement.className, /semyra-player-fullscreen/);
assert.match(fakeDocument.body.className, /semyra-player-fullscreen/);
const autoHideTimer = lifecycleTarget.pendingTimers.findLast(isAutoHideTimer);
assert.ok(autoHideTimer, 'fullscreen playing must schedule a 3000 ms auto-hide');
for (let index = 0; index < 20; index += 1) {
    rootListeners.get('semyra:iptv-media-state')({detail: {
        playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'playing', channelId: 7,
        channelName: 'Globo News', mediaKind: 'vod', position: 100 + index, duration: 3600, seekable: true,
        fullscreen: true, paused: false,
    }});
    rootListeners.get('semyra:iptv-media-state')({detail: {
        playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'playing', channelId: 7,
        channelName: 'Globo News', mediaKind: 'vod', position: 200 + index, duration: 3600, seekable: true,
        fullscreen: true, paused: false,
    }});
    rootListeners.get('semyra:iptv-media-state')({detail: {
        playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'playing', channelId: 7,
        channelName: 'Globo News', mediaKind: 'vod', position: 200 + index, duration: 3600 + index, seekable: true,
        fullscreen: true, paused: false,
    }});
}
assert.equal(lifecycleTarget.pendingTimers.filter(isAutoHideTimer).length, 1, 'MPV status/time/duration spam must not reset the user timer');
assert.equal(lifecycleTarget.pendingTimers.find(isAutoHideTimer), autoHideTimer);
lifecycleTarget.pendingTimers.splice(lifecycleTarget.pendingTimers.indexOf(autoHideTimer), 1);
autoHideTimer();
assert.match(root.className, /is-native-controls-hidden/);
assert.equal(requests.filter((item) => typeof item.hidden === 'boolean').at(-1).hidden, true);
const geometryBeforeNativeActivity = requests.filter((item) => item.action === 'player-geometry').length;
rootListeners.get('semyra:player-user-activity')({});
assert.doesNotMatch(root.className, /is-native-controls-hidden/, 'native cursor activity must reveal native controls');
assert.equal(requests.filter((item) => typeof item.hidden === 'boolean').at(-1).hidden, false);
assert.equal(requests.filter((item) => item.action === 'player-geometry').length, geometryBeforeNativeActivity + 1, 'show transition must restore geometry once');
const secondAutoHideTimer = lifecycleTarget.pendingTimers.findLast(isAutoHideTimer);
assert.ok(secondAutoHideTimer, 'native cursor activity must arm a fresh timer');
assert.doesNotMatch(root.className, /is-native-controls-hidden/, 'controls remain visible before the 3000 ms timer fires');
lifecycleTarget.pendingTimers.splice(lifecycleTarget.pendingTimers.indexOf(secondAutoHideTimer), 1);
secondAutoHideTimer();
assert.match(root.className, /is-native-controls-hidden/, 'parked pointer must not block auto-hide');
rootListeners.get('semyra:player-user-activity')({});
assert.doesNotMatch(root.className, /is-native-controls-hidden/, 'repeated native activity must reveal controls again');
for (let cycle = 0; cycle < 3; cycle += 1) {
    const cycleTimer = lifecycleTarget.pendingTimers.findLast(isAutoHideTimer);
    assert.ok(cycleTimer, 'cycle ' + (cycle + 1) + ' must arm the JS-owned 3s timer');
    lifecycleTarget.pendingTimers.splice(lifecycleTarget.pendingTimers.indexOf(cycleTimer), 1);
    cycleTimer();
    assert.match(root.className, /is-native-controls-hidden/, 'cycle ' + (cycle + 1) + ' must hide controls');
    assert.equal(requests.filter((item) => typeof item.hidden === 'boolean').at(-1).hidden, true);
    rootListeners.get('semyra:player-user-activity')({});
    assert.doesNotMatch(root.className, /is-native-controls-hidden/, 'cycle ' + (cycle + 1) + ' activity must reveal controls');
    assert.equal(requests.filter((item) => typeof item.hidden === 'boolean').at(-1).hidden, false);
}
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'c'.repeat(32), state: 'paused', channelId: 7,
    channelName: 'Globo News', mediaKind: 'vod', position: 66, duration: 3600, seekable: true,
    fullscreen: true, paused: true, muted: true, volume: 35,
}});
assert.equal(registry.get('[data-iptv-native-playback]').attributes.get('aria-label'), 'Reproduzir');
assert.equal(registry.get('[data-iptv-native-mute]').attributes.get('aria-label'), 'Ativar som');
assert.equal(registry.get('[data-iptv-native-volume]').value, '35');
const timerArmedBeforePause = lifecycleTarget.pendingTimers.findLast(isAutoHideTimer);
assert.equal(timerArmedBeforePause, undefined, 'paused playback must cancel the auto-hide timer');
assert.doesNotMatch(root.className, /is-native-controls-hidden/, 'paused playback must keep controls visible');
documentListeners.get('keydown')({key: 'Escape', preventDefault() {}});
assert.deepEqual(requests.at(-1), {action: 'player-fullscreen', sessionId: 'c'.repeat(32), fullscreen: false});
rootListeners.get('semyra:iptv-result')({detail: {
    action: 'player-fullscreen', ok: true, playbackKind: 'native-mpv', sessionId: 'c'.repeat(32),
    state: 'paused', channelId: 7, channelName: 'Globo News', mediaKind: 'vod', fullscreen: false, paused: true,
}});
assert.doesNotMatch(root.className, /is-native-fullscreen/);
assert.doesNotMatch(fakeDocument.documentElement.className, /semyra-player-fullscreen/);
assert.deepEqual(lifecycleTarget.restoredScroll, [10, 120]);
lifecycleTarget.intersectionCallback([{isIntersecting: false, intersectionRatio: 0}]);
const pendingAfterIntersection = lifecycleTarget.pendingTimers.splice(0);
pendingAfterIntersection.find((callback) => callback.testDelay === 50)();
assert.deepEqual(requests.findLast((item) => item.action === 'player-geometry'), {
    action: 'player-geometry', sessionId: 'c'.repeat(32), x: 0, y: 0, width: 0, height: 0,
    viewportWidth: 1200, viewportHeight: 800, visible: false,
});
registry.get('[data-iptv-native-mute]').emit('click');
assert.equal(requests.at(-1).command, 'unmute');
registry.get('[data-iptv-native-volume]').value = '55';
registry.get('[data-iptv-native-volume]').emit('input');
assert.equal(requests.at(-1).value, 55);
registry.get('[data-iptv-native-stop]').emit('click');
assert.equal(requests.at(-1).action, 'player-control');
assert.equal(requests.at(-1).command, 'stop');
assert.equal(requests.at(-1).sessionId, 'c'.repeat(32));
assert.equal(registry.get('[data-iptv-native-controls]').hidden, true);

channels.emit('click', {target: playButton});
rootListeners.get('semyra:iptv-result')({detail: {
    action: 'view-start', ok: true, playbackKind: 'native-mpv', sessionId: 'd'.repeat(32), state: 'playing', channelId: 7, channelName: 'Globo News', errorCode: null, paused: false, muted: false, volume: 35,
}});
assert.equal(registry.get('[data-iptv-native-playback]').attributes.get('aria-label'), 'Pausar', 'new media clears the previous paused state');
assertNativeControlIcon('[data-iptv-native-playback]', 'pause');
assert.equal(registry.get('[data-iptv-native-volume]').value, '35', 'new media preserves the confirmed volume');
rootListeners.get('pagehide')();
assert.equal(requests.at(-1).action, 'player-control');
assert.equal(requests.at(-1).command, 'stop');
assert.equal(requests.at(-1).sessionId, 'd'.repeat(32));
assert.equal(stage.dataset.state, 'idle');
assert.equal(registry.get('[data-iptv-player-empty]').hidden, false);
assert.equal(playButton.disabled, false);
assert.equal(registry.get('[data-iptv-native-controls]').hidden, true);
rootListeners.get('semyra:iptv-result')({detail: {action: 'view-status', ok: true, state: 'idle'}});
channels.emit('click', {target: playButton});
assert.equal(requests.at(-1).action, 'view-start', 'returning idle must allow the same channel');
rootListeners.get('semyra:iptv-result')({detail: {
    action: 'view-start', ok: true, playbackKind: 'native-mpv', sessionId: 'e'.repeat(32), state: 'loading', channelId: 7, channelName: 'Globo News', errorCode: null,
}});
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'e'.repeat(32), state: 'failed', channelId: 7,
    channelName: 'Globo News', errorCode: 'mpv_http_error', mediaKind: null,
}});
assert.equal(registry.get('[data-iptv-player-error]').hidden, false);
assert.equal(registry.get('[data-iptv-error-title]').textContent, 'Não foi possível reproduzir este conteúdo.');
assert.equal(registry.get('[data-iptv-native-controls]').hidden, true);
assert.deepEqual(requests.findLast((item) => item.action === 'player-geometry'), {
    action: 'player-geometry', sessionId: 'e'.repeat(32), x: 0, y: 0, width: 0, height: 0,
    viewportWidth: 1200, viewportHeight: 800, visible: false,
});
registry.get('[data-iptv-retry]').emit('click');
assert.equal(requests.at(-1).action, 'view-start');
rootListeners.get('semyra:iptv-result')({detail: {
    action: 'view-start', ok: true, playbackKind: 'native-mpv', sessionId: 'f'.repeat(32), state: 'loading', channelId: 7, channelName: 'Globo News', errorCode: null,
}});
rootListeners.get('semyra:iptv-media-state')({detail: {
    playbackKind: 'native-mpv', sessionId: 'f'.repeat(32), state: 'failed', channelId: 7,
    channelName: 'Globo News', errorCode: 'mpv_demux_error', mediaKind: null,
}});
registry.get('[data-iptv-choose-channel]').emit('click');
assert.equal(requests.findLast((item) => item.action === 'player-control').command, 'stop');
assert.equal(stage.dataset.state, 'idle');

query.value = 'antiga';
form.emit('submit');
const staleSearchToken = requests.at(-1).clientRequestId;
query.value = 'nova';
form.emit('submit');
const currentSearchToken = requests.at(-1).clientRequestId;
assert.notEqual(staleSearchToken, currentSearchToken);
rootListeners.get('semyra:iptv-result')({detail: {action: 'search', ok: true, clientRequestId: staleSearchToken, offset: 0, hasMore: false, channels: [
    {id: 88, name: 'Resultado antigo', groupName: 'Teste'},
]}});
assert.equal(channels.attributes.get('aria-busy'), 'true', 'stale result must leave current loading in place');
const replacementsBefore = channels.replaceCount;
rootListeners.get('semyra:iptv-result')({detail: {action: 'search', ok: true, clientRequestId: currentSearchToken, offset: 0, hasMore: false, channels: Array.from({length: 50}, (_, index) => ({
    id: 100 + index, name: 'Canal ' + index, groupName: 'Carga',
}))}});
assert.equal(channels.children.length, 50);
assert.equal(channels.replaceCount, replacementsBefore + 1, 'fifty cards must be committed to the DOM in one replacement');
assert.equal(channels.attributes.get('aria-busy'), 'false');

registry.get('[data-iptv-settings-open]').emit('click');
let activeSourceCard = sourceList.children.find((item) => item.dataset.sourceId === '1');
sourceList.emit('click', {target: find(activeSourceCard, (item) => item.dataset.iptvAction === 'remove')});
assert.match(confirmations.at(-1), /catálogo local associado também será removido/);
assert.equal(settings.dataset.settingsState, 'removing-source');
assert.equal(registry.get('[data-iptv-settings-loader-title]').textContent, 'Removendo fonte…');
const removeActiveToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'remove', ok: true, clientRequestId: removeActiveToken, removed: true}});
assert.deepEqual(requests.slice(-2).map((item) => item.action), ['groups', 'search']);
const fallbackGroupsToken = requests.at(-2).clientRequestId;
const fallbackSearchToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'groups', ok: true, clientRequestId: fallbackGroupsToken, groups: []}});
rootListeners.get('semyra:iptv-result')({detail: {action: 'search', ok: true, clientRequestId: fallbackSearchToken, offset: 0, hasMore: false, channels: []}});
assert.equal(settings.dataset.settingsState, 'idle');
assert.equal(registry.get('[data-iptv-source-select]').value, '2', 'removing current source must deterministically select the first remaining source');

for (const sourceId of [3, 4]) {
    const sourceCard = sourceList.children.find((item) => item.dataset.sourceId === String(sourceId));
    sourceList.emit('click', {target: find(sourceCard, (item) => item.dataset.iptvAction === 'remove')});
    const removeToken = requests.at(-1).clientRequestId;
    rootListeners.get('semyra:iptv-result')({detail: {action: 'remove', ok: true, clientRequestId: removeToken, removed: true}});
    assert.equal(settings.dataset.settingsState, 'idle');
    assert.equal(registry.get('[data-iptv-source-select]').value, '2', 'removing an inactive source must preserve selection');
}

activeSourceCard = sourceList.children.find((item) => item.dataset.sourceId === '2');
sourceList.emit('click', {target: find(activeSourceCard, (item) => item.dataset.iptvAction === 'remove')});
const removeLastToken = requests.at(-1).clientRequestId;
rootListeners.get('semyra:iptv-result')({detail: {action: 'remove', ok: true, clientRequestId: removeLastToken, removed: true}});
assert.equal(registry.get('[data-iptv-onboarding]').hidden, false, 'removing the final source must return to onboarding');
assert.equal(registry.get('[data-iptv-library]').hidden, true);
assert.equal(settings.dataset.settingsState, 'idle');

rootListeners.get('semyra:iptv-result')({detail: {action: 'list', ok: true, clientRequestId: initialListToken, sources: [
    {id: 9, name: 'Xtream VOD', type: 'xtream', channelCount: 20, movieCount: 1200, seriesCount: 80, vodSyncState: 'ready', lastRefreshStatus: 'ready'},
]}});
const xtreamCard = sourceList.children.find((item) => item.dataset.sourceId === '9');
assert.match(find(xtreamCard, (item) => item.className === 'iptv-source-meta' && item.textContent.includes('filmes')).textContent, /1\.200 filmes/);
const vodButton = find(xtreamCard, (item) => item.dataset.iptvAction === 'vod-refresh');
sourceList.emit('click', {target: vodButton});
const vodToken = requests.at(-1).clientRequestId;
assert.equal(settings.dataset.settingsState, 'syncing-vod');
assert.equal(requests.at(-1).action, 'vod-refresh');
rootListeners.get('semyra:iptv-result')({detail: {action: 'vod-refresh', ok: true, clientRequestId: vodToken, source: {
    id: 9, name: 'Xtream VOD', type: 'xtream', channelCount: 20, movieCount: 1300, seriesCount: 90, vodSyncState: 'ready', lastRefreshStatus: 'ready',
}}});
assert.equal(settings.dataset.settingsState, 'idle');
assert.equal(registry.get('[data-iptv-feedback]').textContent, 'Filmes e séries sincronizados.');

let logoutPrevented = 0;
logoutForm.emit('submit', {preventDefault: () => { logoutPrevented += 1; }});
assert.equal(logoutPrevented, 0);

const browserOnly = iptv.createIptvCatalog({document: {querySelector: () => null}, addEventListener: () => {}});
assert.equal(browserOnly.start(), false);

console.log('desktop-iptv tests passed');
