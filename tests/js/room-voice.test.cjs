'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const createVoice = require('../../public/assets/js/room-voice.js');

const tick = () => new Promise((resolve) => setImmediate(resolve));
const element = () => {
    const listeners = new Map();
    return {
        hidden: false, disabled: false, value: '100', textContent: '', children: [], dataset: {},
        classList: {toggle() {}},
        setAttribute(name, value) { this[name] = value; },
        addEventListener(type, listener) { listeners.set(type, listener); },
        async emit(type, event = {}) { return listeners.get(type)?.(event); },
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; },
        remove() { this.removed = true; },
        querySelector() { return null; },
    };
};

const harness = () => {
    const controls = new Map([
        ['[data-voice-microphone]', element()], ['[data-voice-microphone-label]', element()],
        ['[data-voice-microphone-icon]', element()], ['[data-voice-listen]', element()],
        ['[data-voice-listen-label]', element()], ['[data-voice-listen-icon]', element()],
        ['[data-voice-volume]', element()], ['[data-voice-connection]', element()],
        ['[data-voice-status]', element()], ['[data-voice-participants]', element()],
        ['[data-voice-audio-mount]', element()],
    ]);
    const panel = {querySelector: (selector) => controls.get(selector) ?? null};
    const shell = {dataset: {
        livekitVoiceTokenUrl: '/room/ROOM1234/livekit/voice-token',
        livekitClientSrc: '/assets/vendor/livekit/livekit-client.umd.js?v=2.22.3', csrfToken: 'csrf',
    }, querySelector: () => element()};
    const document = {
        querySelector(selector) {
            if (selector === '[data-room-shell]') return shell;
            if (selector === '[data-room-voice]') return panel;
            return null;
        },
        createElement: element,
        head: {append() {}},
    };
    const windowListeners = new Map();
    const window = {
        crypto: {getRandomValues(bytes) { bytes.fill(7); return bytes; }},
        setTimeout() { return 1; },
        addEventListener(type, listener) { windowListeners.set(type, listener); },
    };
    const requests = [];
    const fetch = async (url, options) => {
        requests.push({url, options, body: new URLSearchParams(options.body)});
        return {ok: true, status: 201, json: async () => ({
            server_url: 'wss://livekit.example.test', participant_token: 'jwt',
            participant_identity: `smy_c_${'a'.repeat(32)}`,
        })};
    };
    class FakeRoom {
        static instances = [];
        constructor(options) {
            this.options = options; this.events = new Map(); this.remoteParticipants = new Map();
            this.localParticipant = {
                identity: `smy_c_${'a'.repeat(32)}`, name: 'Pedro', trackPublications: new Map(),
                calls: [], setMicrophoneEnabled: async (...args) => this.localParticipant.calls.push(args),
            };
            FakeRoom.instances.push(this);
        }
        on(type, listener) { this.events.set(type, listener); }
        async connect(url, token, options) { this.connectArgs = {url, token, options}; }
        async startAudio() { this.startedAudio = true; }
        async disconnect() { this.disconnected = true; }
        emit(type, ...args) { return this.events.get(type)?.(...args); }
    }
    const sdk = {
        Room: FakeRoom,
        RoomEvent: {
            ParticipantConnected: 'participantConnected', ParticipantDisconnected: 'participantDisconnected',
            TrackPublished: 'trackPublished', TrackSubscribed: 'trackSubscribed',
            TrackUnsubscribed: 'trackUnsubscribed', TrackMuted: 'trackMuted', TrackUnmuted: 'trackUnmuted',
            ActiveSpeakersChanged: 'activeSpeakersChanged', Reconnecting: 'reconnecting',
            Reconnected: 'reconnected', Disconnected: 'disconnected',
        },
        Track: {Source: {Microphone: 'microphone'}},
    };
    const controller = createVoice({window, document, fetch, loadSdk: async () => sdk});
    return {controller, controls, requests, FakeRoom, sdk, windowListeners};
};

test('connects independently with a per-document identity and microphone initially disabled', async () => {
    const {controller, requests, FakeRoom} = harness();
    await tick(); await tick();
    const room = FakeRoom.instances[0];
    assert.equal(requests.length, 1);
    assert.match(requests[0].body.get('voice_instance_id'), /^[a-f0-9]{32}$/);
    assert.equal(room.connectArgs.options.autoSubscribe, false);
    assert.equal(room.localParticipant.calls.length, 0);
    assert.equal(controller.state().microphoneEnabled, false);
});

test('subscribes only microphone publications and ignores screen or camera tracks', async () => {
    const {FakeRoom, sdk} = harness();
    await tick(); await tick();
    const room = FakeRoom.instances[0];
    const mic = {source: 'microphone', isSubscribed: false, setSubscribed(value) { this.value = value; }};
    const screen = {source: 'screen_share', isSubscribed: false, setSubscribed(value) { this.value = value; }};
    room.emit(sdk.RoomEvent.TrackPublished, mic, {identity: 'remote'});
    room.emit(sdk.RoomEvent.TrackPublished, screen, {identity: 'remote'});
    assert.equal(mic.value, true);
    assert.equal(screen.value, undefined);
});

test('enables microphone only after explicit click with voice processing constraints', async () => {
    const {controller, controls, FakeRoom} = harness();
    await tick(); await tick();
    await controls.get('[data-voice-microphone]').emit('click');
    const call = FakeRoom.instances[0].localParticipant.calls[0];
    assert.equal(call[0], true);
    assert.deepEqual(call[1], {echoCancellation: true, noiseSuppression: true, autoGainControl: true});
    assert.equal(controller.state().microphoneEnabled, true);
});

test('attaches remote microphone once and local receive mute is independent', async () => {
    const {controls, FakeRoom, sdk} = harness();
    await tick(); await tick();
    const room = FakeRoom.instances[0];
    const audio = element();
    const track = {sid: 'TR1', attach: () => audio, detach() {}};
    const publication = {source: 'microphone', trackSid: 'TR1'};
    room.emit(sdk.RoomEvent.TrackSubscribed, track, publication, {identity: 'remote', name: 'Ana'});
    assert.equal(controls.get('[data-voice-audio-mount]').children.length, 1);
    await controls.get('[data-voice-listen]').emit('click');
    assert.equal(audio.muted, true);
});

test('reports reconnect state and cleans microphone up on page hide', async () => {
    const {controls, FakeRoom, sdk, windowListeners} = harness();
    await tick(); await tick();
    const room = FakeRoom.instances[0];

    room.emit(sdk.RoomEvent.Reconnecting);
    assert.equal(controls.get('[data-voice-connection]').textContent, 'Reconectando...');
    room.emit(sdk.RoomEvent.Reconnected);
    assert.equal(controls.get('[data-voice-connection]').textContent, 'Conectado');

    windowListeners.get('pagehide')();
    await tick();
    assert.equal(room.localParticipant.calls.at(-1)[0], false);
    assert.equal(room.disconnected, true);
});
