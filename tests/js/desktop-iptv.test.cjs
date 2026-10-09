'use strict';

const assert = require('node:assert/strict');
const iptv = require('../../public/assets/js/desktop-iptv.js');

assert.equal(typeof iptv.createIptvCatalog, 'function');
assert.equal(iptv.mediaStatusLabel('preparing'), 'Preparando canal…');
assert.equal(iptv.mediaStatusLabel('streaming'), 'Reproduzindo agora');
assert.equal(iptv.mediaStatusLabel('reconnecting'), 'Reconectando…');
assert.equal(iptv.mediaStatusLabel('failed', 'media_runtime_unavailable'), 'Player local indisponível neste dispositivo.');
assert.equal(iptv.mediaStatusLabel('failed', 'invalid_mpegts'), 'Não foi possível abrir este canal.');
assert.equal(iptv.channelMonogram('Globo News'), 'GN');
assert.equal(iptv.channelMonogram('Band'), 'BA');
assert.equal(iptv.channelMonogram(''), 'TV');
assert.equal(iptv.shouldRenderRemoteLogo('https://provider.example/logo.png?token=secret'), false);

const playbackUrl = '/__desktop/playback/' + 'a'.repeat(64) + '/index.m3u8';
assert.equal(iptv.validPlaybackUrl(playbackUrl), true);
assert.equal(iptv.validPlaybackUrl(playbackUrl + '?token=bad'), false);
assert.equal(iptv.validPlaybackUrl('/__desktop/playback/../index.m3u8'), false);
assert.equal(iptv.localViewAvailable({capabilities: ['iptv.play']}), false);
assert.equal(iptv.localViewAvailable({capabilities: ['iptv.play', 'iptv.local-view']}), true);

const calls = [];
class FakeHls {
    static Events = {ERROR: 'error', MANIFEST_PARSED: 'manifest'};
    static isSupported() { return true; }
    constructor() { FakeHls.last = this; }
    on(name, callback) { this[name] = callback; }
    loadSource(url) { calls.push(['load', url]); }
    attachMedia(video) { calls.push(['attach', video]); }
    destroy() { calls.push(['destroy']); }
}
const video = {
    volume: 1, muted: false,
    pause: () => calls.push(['pause']),
    removeAttribute: (name) => calls.push(['remove', name]),
    load: () => calls.push(['reset']),
    play: () => Promise.resolve(),
    canPlayType: () => '',
    addEventListener() {},
};
const autoplayStates = [];
const player = iptv.createHlsPlaybackController({Hls: FakeHls}, video, () => calls.push(['fatal']), (blocked) => autoplayStates.push(blocked));
assert.equal(player.attach(playbackUrl), true);
assert.deepEqual(calls.slice(-2), [['load', playbackUrl], ['attach', video]]);
FakeHls.last.manifest();
assert.deepEqual(player.setVolume(.35), {volume: .35, muted: false});
assert.deepEqual(player.toggleMuted(), {volume: .35, muted: true});
assert.deepEqual(player.toggleMuted(), {volume: .35, muted: false});
assert.equal(player.attach(playbackUrl.replace('a'.repeat(64), 'b'.repeat(64))), true);
assert.equal(calls.filter((call) => call[0] === 'destroy').length, 1);
FakeHls.last.error(null, {fatal: true});
assert.equal(calls.filter((call) => call[0] === 'fatal').length, 1);
player.destroy();
assert.equal(calls.filter((call) => call[0] === 'destroy').length, 2);

let blocked = false;
const blockedVideo = {
    volume: 1, muted: false,
    pause() {}, removeAttribute() {}, load() {}, addEventListener() {}, canPlayType: () => 'maybe',
    play: () => Promise.reject(new Error('gesture required')),
};
const blockedPlayer = iptv.createHlsPlaybackController({}, blockedVideo, () => assert.fail('autoplay is not stream failure'), (value) => { blocked = value; });
assert.equal(blockedPlayer.attach(playbackUrl), true);

class FakeClassList {
    constructor(owner) { this.owner = owner; }
    toggle(name, force) {
        const names = new Set(this.owner.className.split(/\s+/).filter(Boolean));
        if (force) names.add(name); else names.delete(name);
        this.owner.className = [...names].join(' ');
    }
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
        if (selector === '[data-iptv-media-start]' && this.dataset.iptvMediaStart) return this;
        if (selector === '[data-iptv-action]' && this.dataset.iptvAction) return this;
        if (selector === '[data-source-id]' && this.dataset.sourceId) return this;
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
const registry = new Map();
const root = new FakeElement('section');
root.querySelector = (selector) => registry.get(selector) || null;
root.querySelectorAll = (selector) => selector === '[data-iptv-media-start]'
    ? (registry.get('[data-iptv-channel-list]')?.children || []).filter((item) => item.dataset.iptvMediaStart)
    : [];

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
const stage = register('[data-iptv-player-stage]');
stage.requestFullscreen = () => { fakeDocument.fullscreenElement = stage; return Promise.resolve(); };
const settings = register('[data-iptv-settings]', new FakeElement('dialog'));
const settingsContent = register('[data-iptv-settings-content]', new FakeElement('div'));
const settingsLoader = register('[data-iptv-settings-loader]', new FakeElement('div'));
register('[data-iptv-settings-loader-title]', new FakeElement('strong'));
register('[data-iptv-settings-loader-copy]', new FakeElement('p'));
const channels = register('[data-iptv-channel-list]');
const lifecycleCalls = [];
const lifecycleVideo = register('[data-iptv-video]', Object.assign(new FakeElement('video'), {
    volume: 1, muted: false, src: '',
    pause: () => lifecycleCalls.push('pause'),
    removeAttribute: () => lifecycleCalls.push('remove-src'),
    load: () => lifecycleCalls.push('load'),
    play: () => Promise.resolve(),
    canPlayType: () => '',
}));
for (const selector of [
    '[data-iptv-browser-notice]', '[data-iptv-desktop-panel]', '[data-iptv-source-loading]', '[data-iptv-onboarding]', '[data-iptv-library]',
    '[data-iptv-source-switcher]', '[data-iptv-current-source]', '[data-iptv-source-select-wrap]', '[data-iptv-source-select]',
    '[data-iptv-settings-open]', '[data-iptv-onboarding-open]', '[data-iptv-settings-close]', '[data-iptv-pick-file]',
    '[data-iptv-source-list]', '[data-iptv-feedback]', '[data-iptv-player-empty]', '[data-iptv-player-state]',
    '[data-iptv-player-state-label]', '[data-iptv-player-error]', '[data-iptv-autoplay]', '[data-iptv-live-badge]',
    '[data-iptv-current-channel]', '[data-iptv-media-status]', '[data-iptv-media-stop]', '[data-iptv-retry]',
    '[data-iptv-choose-channel]', '[data-iptv-mute]', '[data-iptv-volume]', '[data-iptv-volume-icon]',
    '[data-iptv-muted-icon]', '[data-iptv-fullscreen]', '[data-iptv-fullscreen-icon]', '[data-iptv-compress-icon]',
    '[data-iptv-previous]', '[data-iptv-next]', '[data-iptv-page-label]', '[data-iptv-result-count]',
]) if (!registry.has(selector)) register(selector);
registry.get('[data-iptv-pick-file]').tagName = 'BUTTON';
settingsContent.appendChild(urlForm);
settingsContent.appendChild(registry.get('[data-iptv-pick-file]'));
settingsContent.appendChild(registry.get('[data-iptv-source-list]'));

const logoutForm = new FakeElement('form');
const fakeDocument = {
    fullscreenElement: null,
    activeElement: null,
    createElement: (tag) => new FakeElement(tag),
    createDocumentFragment: () => Object.assign(new FakeElement('fragment'), {isFragment: true}),
    querySelector: (selector) => selector === '[data-iptv-catalog]' ? root : selector === 'form.account-logout[action="/logout"]' ? logoutForm : null,
    addEventListener: (type, listener) => documentListeners.set(type, listener),
    exitFullscreen: () => { fakeDocument.fullscreenElement = null; return Promise.resolve(); },
};
const lifecycleTarget = {
    Hls: class extends FakeHls { destroy() { lifecycleCalls.push('hls-destroy'); } },
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    dispatchEvent: (event) => requests.push(event.detail),
    addEventListener: (type, listener) => rootListeners.set(type, listener),
    setTimeout: (callback) => { lifecycleTarget.pendingTimer = callback; return 1; },
    confirm: (message) => { confirmations.push(message); return true; },
    document: fakeDocument,
};

assert.equal(iptv.createIptvCatalog(lifecycleTarget).start(), true);
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
const playableCard = channels.children[0];
const sourceList = registry.get('[data-iptv-source-list]');
const firstSource = sourceList.children[0];
const secondSource = sourceList.children[1];
assert.equal(find(firstSource, (item) => item.className === 'iptv-source-current').textContent, 'Em uso');
assert.equal(find(firstSource, (item) => item.dataset.iptvAction === 'select'), null);
assert.equal(find(secondSource, (item) => item.dataset.iptvAction === 'select').textContent, 'Selecionar');
assert.equal(find(sourceList, (item) => item.textContent === 'Usar'), null);

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

const card = playableCard;
assert.equal(card.tagName, 'BUTTON');
assert.equal(card.querySelectorAll?.('img')?.length || 0, 0);
channels.emit('click', {target: card});
assert.equal(requests.at(-1).action, 'view-start');
assert.equal(requests.at(-1).channelId, 7);
assert.equal(stage.dataset.state, 'preparing');

rootListeners.get('semyra:iptv-result')({detail: {
    action: 'view-start', ok: true, playbackUrl, state: 'streaming', channelId: 7, channelName: 'Globo News', errorCode: null,
}});
assert.equal(stage.dataset.state, 'streaming');
assert.equal(registry.get('[data-iptv-live-badge]').hidden, false);
assert.equal(registry.get('[data-iptv-player-empty]').hidden, true, 'idle content must not leak into streaming');
assert.equal(registry.get('[data-iptv-player-state]').hidden, true, 'preparing overlay must not leak into streaming');
const startsBefore = requests.filter((item) => item.action === 'view-start').length;
channels.emit('click', {target: card});
assert.equal(requests.filter((item) => item.action === 'view-start').length, startsBefore);

rootListeners.get('semyra:iptv-media-state')({detail: {state: 'reconnecting', channelId: 7, channelName: 'Globo News'}});
assert.equal(stage.dataset.state, 'reconnecting');
rootListeners.get('semyra:iptv-media-state')({detail: {state: 'failed', channelId: 7, channelName: 'Globo News', errorCode: 'pipeline_exited'}});
assert.equal(registry.get('[data-iptv-player-error]').hidden, false);
registry.get('[data-iptv-retry]').emit('click');
assert.equal(requests.at(-1).action, 'view-start');

registry.get('[data-iptv-volume]').value = '25';
registry.get('[data-iptv-volume]').emit('input');
assert.equal(lifecycleVideo.volume, .25);
registry.get('[data-iptv-mute]').emit('click');
assert.equal(lifecycleVideo.muted, true);
registry.get('[data-iptv-mute]').emit('click');
assert.equal(lifecycleVideo.muted, false);
registry.get('[data-iptv-fullscreen]').emit('click');
assert.equal(fakeDocument.fullscreenElement, stage);
registry.get('[data-iptv-media-stop]').emit('click');
assert.equal(requests.at(-1).action, 'view-stop');
assert.equal(stage.dataset.state, 'idle');
assert.equal(card.disabled, false);
const restartsBefore = requests.filter((item) => item.action === 'view-start').length;
channels.emit('click', {target: card});
assert.equal(requests.filter((item) => item.action === 'view-start').length, restartsBefore + 1, 'same channel must restart after idle');
rootListeners.get('semyra:iptv-result')({detail: {
    action: 'view-start', ok: true, playbackUrl, state: 'streaming', channelId: 7, channelName: 'Globo News', errorCode: null,
}});
const pagehideDestroyCount = lifecycleCalls.filter((item) => item === 'hls-destroy').length;
rootListeners.get('pagehide')();
assert.equal(requests.at(-1).action, 'view-stop');
assert.equal(stage.dataset.state, 'idle');
assert.equal(registry.get('[data-iptv-player-empty]').hidden, false);
assert.equal(card.disabled, false);
assert.ok(lifecycleCalls.filter((item) => item === 'hls-destroy').length > pagehideDestroyCount);
rootListeners.get('semyra:iptv-result')({detail: {action: 'view-status', ok: true, state: 'idle'}});
channels.emit('click', {target: card});
assert.equal(requests.at(-1).action, 'view-start', 'returning idle must allow the same channel');

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

let logoutPrevented = 0;
logoutForm.emit('submit', {preventDefault: () => { logoutPrevented += 1; }});
assert.equal(logoutPrevented, 0);
assert.ok(lifecycleCalls.includes('hls-destroy'));
assert.deepEqual(lifecycleCalls.slice(-3), ['pause', 'remove-src', 'load']);

const browserOnly = iptv.createIptvCatalog({document: {querySelector: () => null}, addEventListener: () => {}});
assert.equal(browserOnly.start(), false);

setImmediate(() => {
    assert.equal(blocked, true);
    console.log('desktop-iptv tests passed');
});
