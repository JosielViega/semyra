'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const createPlayer = require('../../public/assets/js/room-livekit-player.js');

global.CustomEvent = class CustomEvent {
    constructor(type, options) { this.type = type; this.detail = options?.detail; }
};

const element = (tagName = 'div') => ({
    tagName: tagName.toUpperCase(),
    hidden: false,
    textContent: '',
    children: [],
    classList: {add() {}, toggle() {}},
    append(child) { this.children.push(child); child.parent = this; },
    replaceChildren() { this.children = []; },
    remove() {
        if (this.parent) this.parent.children = this.parent.children.filter((child) => child !== this);
    },
    addEventListener() {},
});

const createHarness = ({status = 201, payload} = {}) => {
    const listeners = new Map();
    const dispatched = [];
    const mount = element();
    mount.hidden = true;
    const statusElement = element('span');
    const shell = {dataset: {
        livekitViewerTokenUrl: '/room/ABC/livekit/viewer-token',
        livekitClientSrc: '/assets/vendor/livekit/livekit-client.umd.js?v=2.22.3',
        csrfToken: 'csrf',
    }};
    const document = {
        querySelector(selector) {
            if (selector === '[data-room-shell]') return shell;
            if (selector === '[data-livekit-player-mount]') return mount;
            return null;
        },
        getElementById: (id) => id === 'livekit-player-status' ? statusElement : null,
        createElement: element,
        addEventListener(type, listener) { listeners.set(type, listener); },
        dispatchEvent(event) { dispatched.push(event); listeners.get(event.type)?.(event); },
        head: {append() {}},
    };
    const requests = [];
    const responsePayload = payload ?? {
        server_url: 'wss://livekit.example.test',
        participant_token: 'secret-jwt',
        transmission_revision: 4,
        publisher_identity: `smy_i_${'a'.repeat(32)}`,
    };
    const fetch = async (url, options) => {
        const body = new URLSearchParams(options.body);
        requests.push({url, options, body});
        return {status, json: async () => payload === undefined
            ? {...responsePayload, transmission_revision: Number(body.get('transmission_revision'))}
            : responsePayload};
    };
    class FakeRoom {
        static instances = [];
        static nextRemoteParticipants = new Map();
        constructor(options) {
            this.options = options;
            this.events = new Map();
            this.remoteParticipants = FakeRoom.nextRemoteParticipants;
            this.disconnectCalls = 0;
            FakeRoom.instances.push(this);
        }
        on(type, listener) { this.events.set(type, listener); return this; }
        async connect(url, token, options) { this.connectArgs = {url, token, options}; }
        async disconnect() { ++this.disconnectCalls; }
        async startAudio() { ++this.startAudioCalls; }
        emit(type, ...args) { this.events.get(type)?.(...args); }
    }
    const sdk = {
        Room: FakeRoom,
        RoomEvent: {
            ParticipantConnected: 'participantConnected',
            TrackPublished: 'trackPublished',
            TrackSubscribed: 'trackSubscribed',
            TrackUnsubscribed: 'trackUnsubscribed',
            TrackUnpublished: 'trackUnpublished',
            Reconnecting: 'reconnecting',
            Reconnected: 'reconnected',
            Disconnected: 'disconnected',
            AudioPlaybackStatusChanged: 'audioPlaybackStatusChanged',
        },
        Track: {Kind: {Video: 'video', Audio: 'audio'}},
    };
    const storage = new Map();
    const window = {
        localStorage: {
            getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
        },
        SemyraMedia: {
            DEFAULT_PLAYER_VOLUME: 100,
            readStoredPlayerVolume: () => 80,
            writeStoredPlayerVolume: (_store, value) => storage.set('volume', String(value)),
            playerUnmuteVolume: (value, fallback) => value > 0 ? value : fallback,
            playerVolumeSelection: (value, fallback) => {
                const volume = Math.max(0, Math.min(100, Number(value)));
                return {volume, restoreVolume: volume > 0 ? volume : fallback, muted: volume === 0};
            },
        },
    };
    const controller = createPlayer({window, document, fetch, loadSdk: async () => sdk});
    return {controller, document, dispatched, mount, requests, statusElement, FakeRoom, sdk, storage};
};

const iptv = (revision = 4) => ({source: 'iptv', mediaMode: 'live', revision});

test('only IPTV Live requests a revision-bound token and connects subscribe-only', async () => {
    const harness = createHarness();
    await harness.controller.applyTransmission(null);
    await harness.controller.applyTransmission({source: 'youtube', mediaMode: 'live', revision: 1});
    await harness.controller.applyTransmission({source: 'iptv', mediaMode: 'vod', revision: 2});
    assert.equal(harness.requests.length, 0);

    await harness.controller.applyTransmission(iptv());
    assert.equal(harness.requests.length, 1);
    assert.deepEqual([...harness.requests[0].body.keys()], ['_token', 'transmission_revision']);
    assert.equal(harness.requests[0].body.get('_token'), 'csrf');
    assert.equal(harness.requests[0].body.get('transmission_revision'), '4');
    const room = harness.FakeRoom.instances[0];
    assert.deepEqual(room.options, {adaptiveStream: true, disconnectOnPageLeave: true});
    assert.deepEqual(room.connectArgs.options, {autoSubscribe: false});
    assert.equal(harness.mount.hidden, false);
});

test('filters exact publisher and attaches one video and one muted audio track', async () => {
    const harness = createHarness();
    await harness.controller.applyTransmission(iptv());
    const room = harness.FakeRoom.instances[0];
    const expected = {identity: `smy_i_${'a'.repeat(32)}`};
    const unexpected = {identity: `smy_i_${'b'.repeat(32)}`};
    let unexpectedSubscription = null;
    room.emit(harness.sdk.RoomEvent.TrackPublished, {
        kind: 'video', setSubscribed: (value) => { unexpectedSubscription = value; },
    }, unexpected);
    assert.equal(unexpectedSubscription, null);

    let expectedSubscription = null;
    room.emit(harness.sdk.RoomEvent.TrackPublished, {
        kind: 'video', isSubscribed: false,
        setSubscribed: (value) => { expectedSubscription = value; },
    }, expected);
    assert.equal(expectedSubscription, true);

    let unexpectedUnsubscribe = null;
    let unexpectedDetach = 0;
    room.emit(harness.sdk.RoomEvent.TrackSubscribed,
        {kind: 'video', attach: () => element('video'), detach: () => { ++unexpectedDetach; }},
        {kind: 'video', setSubscribed: (value) => { unexpectedUnsubscribe = value; }},
        unexpected);
    assert.equal(unexpectedUnsubscribe, false);
    assert.equal(unexpectedDetach, 1);

    const video = element('video');
    const videoTrack = {kind: 'video', attach: () => video, detach() {}};
    room.emit(harness.sdk.RoomEvent.TrackSubscribed, videoTrack, {kind: 'video'}, expected);
    const audio = element('audio');
    const audioTrack = {kind: 'audio', attach: () => audio, detach() {}};
    room.emit(harness.sdk.RoomEvent.TrackSubscribed, audioTrack, {kind: 'audio'}, expected);
    assert.equal(video.autoplay, true);
    assert.equal(video.playsInline, true);
    assert.equal(audio.muted, true);
    assert.equal(harness.mount.children.length, 2);

    harness.document.dispatchEvent(new CustomEvent('semyra:player-mute-toggle'));
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(audio.muted, false);
    harness.document.dispatchEvent(new CustomEvent('semyra:player-volume-change', {detail: {volume: 35}}));
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(audio.volume, 0.35);
    assert.equal(harness.storage.has('secret-jwt'), false);

    room.emit(harness.sdk.RoomEvent.TrackUnsubscribed, videoTrack);
    assert.equal(harness.mount.children.includes(video), false);
});

test('revision replacement and transmission end disconnect and clear old media', async () => {
    const harness = createHarness();
    await harness.controller.applyTransmission(iptv());
    const oldRoom = harness.FakeRoom.instances[0];
    await harness.controller.applyTransmission(iptv(5));
    assert.equal(oldRoom.disconnectCalls, 1);
    assert.equal(harness.requests.length, 2);
    await harness.controller.applyTransmission(null);
    assert.equal(harness.FakeRoom.instances[1].disconnectCalls, 1);
    assert.equal(harness.mount.hidden, true);
    assert.equal(harness.mount.children.length, 0);
});

test('subscribes existing expected publisher publications after connect', async () => {
    const harness = createHarness();
    const subscriptions = [];
    harness.FakeRoom.nextRemoteParticipants = new Map([['publisher', {
        identity: `smy_i_${'a'.repeat(32)}`,
        trackPublications: new Map([
            ['video', {kind: 'video', isSubscribed: false, setSubscribed: (value) => subscriptions.push(['video', value])}],
            ['audio', {kind: 'audio', isSubscribed: false, setSubscribed: (value) => subscriptions.push(['audio', value])}],
        ]),
    }]]);
    await harness.controller.applyTransmission(iptv());
    assert.deepEqual(subscriptions, [['video', true], ['audio', true]]);
});

test('409 transmission_changed requests presence refresh without connecting', async () => {
    const harness = createHarness({status: 409, payload: {error: 'transmission_changed'}});
    await harness.controller.applyTransmission(iptv());
    assert.equal(harness.FakeRoom.instances.length, 0);
    assert.equal(harness.dispatched.some((event) => event.type === 'semyra:presence-refresh-request'), true);
});

test('autoplay unlock failure remains safely muted', async () => {
    const harness = createHarness();
    await harness.controller.applyTransmission(iptv());
    const room = harness.FakeRoom.instances[0];
    room.startAudio = async () => { throw new Error('blocked'); };
    const audio = element('audio');
    room.emit(harness.sdk.RoomEvent.TrackSubscribed,
        {kind: 'audio', attach: () => audio, detach() {}},
        {kind: 'audio'},
        {identity: `smy_i_${'a'.repeat(32)}`});
    harness.document.dispatchEvent(new CustomEvent('semyra:player-mute-toggle'));
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(audio.muted, true);
    assert.match(harness.statusElement.textContent, /bloqueou/);
});

test('stale response revision never connects', async () => {
    const harness = createHarness({payload: {
        server_url: 'wss://livekit.example.test',
        participant_token: 'secret-jwt',
        transmission_revision: 3,
        publisher_identity: `smy_i_${'a'.repeat(32)}`,
    }});
    await harness.controller.applyTransmission(iptv());
    assert.equal(harness.FakeRoom.instances.length, 0);
});

test('stale async SDK load cannot fetch, connect, or attach after transmission end', async () => {
    let resolveSdk;
    const sdkPromise = new Promise((resolve) => { resolveSdk = resolve; });
    const harness = createHarness();
    const delayed = createPlayer({
        window: {SemyraMedia: harness.controller ? {
            DEFAULT_PLAYER_VOLUME: 100,
            readStoredPlayerVolume: () => 100,
            writeStoredPlayerVolume() {},
            playerUnmuteVolume: (value) => value,
            playerVolumeSelection: (value) => ({volume: value, restoreVolume: value, muted: value === 0}),
        } : null},
        document: harness.document,
        fetch: async () => { throw new Error('stale request must not fetch'); },
        loadSdk: () => sdkPromise,
    });
    const pending = delayed.applyTransmission(iptv());
    await delayed.applyTransmission(null);
    resolveSdk(harness.sdk);
    await pending;
    assert.equal(harness.FakeRoom.instances.length, 0);
});
