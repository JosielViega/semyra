'use strict';

const assert = require('node:assert/strict');
const iptv = require('../../public/assets/js/desktop-iptv.js');

assert.equal(typeof iptv.createIptvCatalog, 'function');
assert.equal(iptv.mediaStatusLabel('preparing'), 'Preparando canal...');
assert.equal(iptv.mediaStatusLabel('streaming'), 'Ao vivo.');
assert.equal(iptv.mediaStatusLabel('reconnecting'), 'Reconectando...');
assert.equal(iptv.mediaStatusLabel('failed', 'media_runtime_unavailable'), 'Player local indisponível neste runtime.');
assert.equal(iptv.mediaStatusLabel('failed', 'invalid_mpegts'), 'Não foi possível abrir este canal.');
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
    pause: () => calls.push(['pause']),
    removeAttribute: (name) => calls.push(['remove', name]),
    load: () => calls.push(['reset']),
    play: () => Promise.resolve(),
    canPlayType: () => '',
};
const player = iptv.createHlsPlaybackController({Hls: FakeHls}, video, () => calls.push(['fatal']));
assert.equal(player.attach(playbackUrl), true);
assert.deepEqual(calls.slice(-2), [['load', playbackUrl], ['attach', video]]);
assert.equal(player.attach(playbackUrl.replace('a'.repeat(64), 'b'.repeat(64))), true);
assert.equal(calls.filter((call) => call[0] === 'destroy').length, 1);
FakeHls.last.error(null, {fatal: true});
assert.equal(calls.filter((call) => call[0] === 'fatal').length, 1);
player.destroy();
assert.equal(calls.filter((call) => call[0] === 'destroy').length, 2);

const nativeVideo = {
    src: '', pause() {}, removeAttribute() { this.src = ''; }, load() {},
    play: () => Promise.resolve(), canPlayType: () => 'maybe',
};
const nativePlayer = iptv.createHlsPlaybackController({}, nativeVideo, () => assert.fail('native HLS should attach'));
assert.equal(nativePlayer.attach(playbackUrl), true);
assert.equal(nativeVideo.src, playbackUrl);
nativePlayer.destroy();
assert.equal(nativeVideo.src, '');

const lifecycleCalls = [];
const lifecycleListeners = new Map();
const rootListeners = new Map();
const logoutForm = {addEventListener: (type, listener) => lifecycleListeners.set('logout:' + type, listener)};
const lifecycleVideo = {
    hidden: true,
    pause: () => lifecycleCalls.push('pause'),
    removeAttribute: () => lifecycleCalls.push('remove-src'),
    load: () => lifecycleCalls.push('load'),
    play: () => Promise.resolve(),
    canPlayType: () => '',
};
const elements = new Map();
function interactiveElement(name) {
    return {
        hidden: false,
        disabled: false,
        dataset: {},
        addEventListener: (type, listener) => lifecycleListeners.set(name + ':' + type, listener),
    };
}
for (const selector of [
    '[data-iptv-url-form]', '[data-iptv-pick-file]', '[data-iptv-source-list]',
    '[data-iptv-channel-list]', '[data-iptv-media-stop]', '[data-iptv-search-form]',
    '[data-iptv-previous]', '[data-iptv-next]', '[data-iptv-media-status]',
]) {
    elements.set(selector, interactiveElement(selector));
}
elements.set('[data-iptv-video]', lifecycleVideo);
const lifecycleRoot = {querySelector: (selector) => elements.get(selector) || null};
const lifecycleTarget = {
    Hls: class extends FakeHls { destroy() { lifecycleCalls.push('hls-destroy'); } },
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    dispatchEvent: () => {},
    addEventListener: (type, listener) => rootListeners.set(type, listener),
    document: {
        querySelector: (selector) => selector === '[data-iptv-catalog]' ? lifecycleRoot : logoutForm,
    },
};
assert.equal(iptv.createIptvCatalog(lifecycleTarget).start(), true);
rootListeners.get('semyra:iptv-result')({detail: {
    action: 'view-start', ok: true, playbackUrl, state: 'streaming', errorCode: null,
}});
let logoutPrevented = 0;
lifecycleListeners.get('logout:submit')({preventDefault: () => { logoutPrevented += 1; }});
assert.equal(logoutPrevented, 0);
assert.equal(lifecycleVideo.hidden, true);
assert.deepEqual(lifecycleCalls.slice(-4), ['hls-destroy', 'pause', 'remove-src', 'load']);

const browserOnly = iptv.createIptvCatalog({
    document: {querySelector: () => null},
    addEventListener: () => {},
});
assert.equal(browserOnly.start(), false);

console.log('desktop-iptv tests passed');
