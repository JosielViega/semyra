'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/js/room-playback.js', 'utf8');

function harness({sourceType = 'iptv', mode = 'vod', owner = true, initialPosition = '40000'} = {}) {
    const windowListeners = new Map(); const documentListeners = new Map(); const nativeRequests = []; const fetchBodies = [];
    let nativePaused = false; let nativePosition = 40;
    const node = (extra = {}) => Object.assign({hidden: false, disabled: false, value: '0', max: '0', dataset: {}, textContent: '',
        listeners: new Map(), addEventListener(type, listener) { this.listeners.set(type, listener); },
        setAttribute(name, value) { this[name] = String(value); }, toggleAttribute(name, enabled) { this[name] = enabled; },
        closest() { return null; }}, extra);
    const sync = node(); const toggle = node(); const playIcon = node(); const pauseIcon = node();
    toggle.querySelector = (selector) => selector === '[data-icon-play]' ? playIcon : pauseIcon;
    const live = node(); const seek = node(); const current = node(); const separator = node(); const duration = node(); const status = node();
    const relative = [node({dataset: {playbackRelative: '-30000'}}), node({dataset: {playbackRelative: '30000'}})];
    const controls = node();
    controls.querySelector = (selector) => ({
        '[data-playback-sync]': sync, '[data-playback-toggle]': toggle, '[data-playback-live]': live,
        '[data-playback-seek]': seek, '[data-playback-current]': current, '[data-playback-separator]': separator,
        '[data-playback-duration]': duration, '[data-playback-status]': status,
    })[selector] || null;
    controls.querySelectorAll = (selector) => selector === '[data-playback-relative]' ? relative : [];
    const initialVideoId = sourceType === 'youtube' ? 'M7lc1UVf-VE' : '';
    const shell = node({dataset: {
        playbackUrl: '/room/ROOM1234/transmission/playback', csrfToken: 'csrf', initialSource: sourceType,
        initialInstanceId: 'a'.repeat(32), initialVideoId, initialRevision: '2', initialOwnerName: 'Owner',
        initialIsOwner: owner ? '1' : '0', initialMediaMode: mode, initialPlaybackState: 'playing',
        initialPlaybackPositionMs: initialPosition, initialPlaybackRevision: '1', initialPlaybackAtLiveEdge: '0',
        initialLiveEdgePositionMs: '', initialLiveSyncPositionMs: '', initialLiveSyncDelayMs: '',
    }});
    const lock = {active: () => false, begin: () => true, end() {}};
    const scrub = {active: false, position: null, isActive() { return this.active; }, positionMs() { return this.position; },
        start(position, allowed) { if (!allowed) return false; this.active = true; this.position = position; return true; },
        cancel() { this.active = false; this.position = null; },
        commit() { if (!this.active) return null; this.active = false; return {action: 'seek', positionMs: this.position}; },
        update(position) { this.position = position; }};
    const media = {
        createResyncLock: () => lock, createScrubbingSession: () => scrub,
        playbackPresentation: ({positionMs, durationMs}) => ({kind: 'vod', current: String(positionMs), duration: String(durationMs)}),
        behindLiveMs: () => null, liveRangeMaxMs: () => 0, sharedPlaybackDispatchKey: () => 'key',
        resyncContextMatches: (a, b) => a?.instanceId === b?.instanceId && a?.transmissionRevision === b?.transmissionRevision && a?.playbackRevision === b?.playbackRevision,
        participantSyncStorageKey: () => 'key', createParticipantSyncTracker: () => ({readyParticipantIds: () => [], observe: () => ({changed: false, shouldResync: false}), synchronizedParticipantIds: () => []}),
        formatTime: String, livePositionLabel: String,
    };
    class CustomEvent { constructor(type, options) { this.type = type; this.detail = options?.detail; } }
    const window = {
        SemyraMedia: media, sessionStorage: {getItem: () => null, setItem() {}, removeItem() {}},
        addEventListener(type, listener) { if (!windowListeners.has(type)) windowListeners.set(type, []); windowListeners.get(type).push(listener); },
        removeEventListener(type, listener) { windowListeners.set(type, (windowListeners.get(type) || []).filter((item) => item !== listener)); },
        dispatchEvent(event) {
            if (event.type === 'semyra:iptv-request') {
                nativeRequests.push(event.detail);
                if (event.detail.command === 'pause') nativePaused = true;
                if (event.detail.command === 'play') nativePaused = false;
                if (event.detail.command === 'seek') nativePosition = event.detail.positionMs / 1000;
                const response = {action: 'publish-control', clientRequestId: event.detail.clientRequestId,
                    ok: true, position: nativePosition, duration: 120, state: 'streaming', paused: nativePaused, seekable: true};
                (windowListeners.get('semyra:iptv-result') || []).slice().forEach((listener) => listener({detail: response}));
            }
            (windowListeners.get(event.type) || []).slice().forEach((listener) => listener(event));
        },
        setTimeout(callback) { return setTimeout(callback, 0); }, clearTimeout,
    };
    const document = {
        querySelector: (selector) => selector === '[data-room-shell]' ? shell : selector === '[data-shared-playback-controls]' ? controls : null,
        querySelectorAll: () => [],
        addEventListener(type, listener) { if (!documentListeners.has(type)) documentListeners.set(type, []); documentListeners.get(type).push(listener); },
        removeEventListener() {}, dispatchEvent(event) {
            (documentListeners.get(event.type) || []).slice().forEach((listener) => listener(event));
        },
    };
    const fetch = async (_url, options) => {
        const body = new URLSearchParams(options.body); fetchBodies.push(body);
        return {ok: true, json: async () => ({transmission: {source: sourceType, instanceId: 'a'.repeat(32), videoId: sourceType === 'youtube' ? initialVideoId : null,
            revision: 2, ownerName: 'Owner', isOwner: owner, mediaMode: mode,
            playback: {state: body.get('action') === 'pause' ? 'paused' : 'playing', positionMs: Number(body.get('position_ms')), revision: 2,
                atLiveEdge: false, liveEdgePositionMs: null, liveSyncPositionMs: null, liveSyncDelayMs: null}}})};
    };
    vm.runInNewContext(source, {window, document, CustomEvent, URLSearchParams, fetch, console, setTimeout, clearTimeout});
    return {controls, toggle, seek, current, duration, relative, nativeRequests, fetchBodies,
        async clickToggle() { toggle.listeners.get('click')(); await new Promise((resolve) => setTimeout(resolve, 10)); },
        async clickRelative(index) { relative[index].listeners.get('click')(); await new Promise((resolve) => setTimeout(resolve, 10)); },
        async seekTo(seconds) { seek.value = String(seconds); seek.listeners.get('input')(); seek.listeners.get('change')(); await new Promise((resolve) => setTimeout(resolve, 10)); },
        async presence(transmission) {
            document.dispatchEvent(new CustomEvent('semyra:presence-updated', {detail: {transmission, participants: []}}));
            await new Promise((resolve) => setTimeout(resolve, 10));
        }};
}

test('IPTV VOD owner sees shared controls and sends fenced native pause before backend', async () => {
    const page = harness();
    assert.equal(page.controls.hidden, false);
    assert.equal(page.toggle.hidden, false);
    assert.equal(page.relative.every((button) => button.hidden === false), true);
    await page.clickToggle();
    assert.deepEqual(page.nativeRequests.map((request) => request.command), ['snapshot', 'pause']);
    assert.equal(page.nativeRequests.every((request) => request.transmissionInstanceId === 'a'.repeat(32) && request.transmissionRevision === 2), true);
    assert.equal(page.nativeRequests.some((request) => 'url' in request || 'sourceUrl' in request), false);
    assert.equal(page.fetchBodies[0].get('action'), 'pause');
    await page.clickToggle();
    assert.equal(page.nativeRequests.at(-1).command, 'play');
    assert.equal(page.fetchBodies.at(-1).get('action'), 'play');
});

test('IPTV VOD non-owner and IPTV Live never expose shared controls', () => {
    assert.equal(harness({owner: false}).controls.hidden, true);
    assert.equal(harness({mode: 'live'}).controls.hidden, true);
});

test('IPTV VOD owner remains visible after presence returns null official position', async () => {
    const page = harness();
    await page.presence({
        source: 'iptv', instance_id: 'a'.repeat(32), youtube_video_id: null, revision: 2,
        owner_name: 'Owner', is_owner: true, media_mode: 'vod',
        playback: {state: 'playing', position_ms: null, revision: 1, at_live_edge: false,
            live_edge_position_ms: null, live_sync_position_ms: null, live_sync_delay_ms: null},
    });
    assert.equal(page.controls.hidden, false);
    assert.equal(page.seek.max, '120');
    assert.equal(page.seek.value, '40');
    assert.equal(page.seek.disabled, false);
    assert.equal(page.relative.every((button) => button.disabled === false), true);
    assert.equal(page.nativeRequests.at(-1).command, 'snapshot');
});

test('IPTV VOD owner reloads with an empty initial position and sees controls', () => {
    const page = harness({initialPosition: ''});
    assert.equal(page.controls.hidden, false);
    assert.equal(page.toggle.hidden, false);
    assert.equal(page.nativeRequests[0].command, 'snapshot');
});

test('IPTV VOD accepts an empty YouTube id and preserves owner controls', async () => {
    const page = harness();
    await page.presence({
        source: 'iptv', instance_id: 'a'.repeat(32), youtube_video_id: '', revision: 2,
        owner_name: 'Owner', is_owner: true, media_mode: 'vod',
        playback: {state: 'playing', position_ms: null, revision: 1, at_live_edge: false,
            live_edge_position_ms: null, live_sync_position_ms: null, live_sync_delay_ms: null},
    });
    assert.equal(page.controls.hidden, false);
    assert.equal(page.toggle.hidden, false);
});

test('IPTV VOD relative and seek controls use the fenced MediaHost publisher', async () => {
    const page = harness();
    await page.clickRelative(1);
    assert.equal(page.nativeRequests.at(-1).command, 'seek');
    assert.equal(page.nativeRequests.at(-1).positionMs, 70000);
    assert.equal(page.fetchBodies.at(-1).get('position_ms'), '70000');
    await page.clickRelative(0);
    assert.equal(page.nativeRequests.at(-1).positionMs, 40000);
    await page.seekTo(90);
    assert.equal(page.nativeRequests.at(-1).command, 'seek');
    assert.equal(page.nativeRequests.at(-1).positionMs, 90000);
    assert.equal(page.fetchBodies.at(-1).get('position_ms'), '90000');
});

test('empty transmission hides controls after an IPTV VOD owner session', async () => {
    const page = harness();
    await page.presence(null);
    assert.equal(page.controls.hidden, true);
});

test('YouTube owner behavior remains available without native IPTV commands', () => {
    const page = harness({sourceType: 'youtube'});
    assert.equal(page.controls.hidden, false);
    assert.equal(page.nativeRequests.length, 0);
});

test('YouTube non-owner retains the previous shared container behavior', () => {
    const page = harness({sourceType: 'youtube', owner: false});
    assert.equal(page.controls.hidden, false);
    assert.equal(page.toggle.hidden, true);
    assert.equal(page.nativeRequests.length, 0);
});
