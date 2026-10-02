'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
    path.join(__dirname, '../../public/assets/js/room-presence.js'),
    'utf8',
);

const scheduled = [];
const requestBodies = [];
const leaveFetches = [];
const beaconRequests = [];
let fetchAttempt = 0;
let randomCalls = 0;
let beaconSucceeds = true;
const listeners = new Map();
const windowListeners = new Map();
const dispatched = [];
let responsePayload = {participants: [], transmission: null};
const element = () => ({
    textContent: '',
    classList: {toggle() {}},
    append() {},
    replaceChildren() {},
});
const elements = new Map([
    ['room-participant-list', element()],
    ['room-participant-count', element()],
    ['room-presence-status', element()],
]);
const presenceContainer = {dataset: {
    presenceUrl: '/room/ABC/presence',
    leaveUrl: '/room/ABC/leave',
    csrfToken: 'csrf',
}};
const document = {
    visibilityState: 'visible',
    querySelector: (selector) => selector === '[data-room-presence]' ? presenceContainer : null,
    getElementById: (id) => elements.get(id) ?? null,
    createDocumentFragment: element,
    createElement: element,
    addEventListener: (type, listener) => listeners.set(type, listener),
    dispatchEvent(event) { dispatched.push(event); },
};
const window = {
    SemyraMedia: {shouldBootstrapLiveEdge: () => false},
    crypto: {
        getRandomValues(bytes) {
            ++randomCalls;
            bytes.forEach((_value, index) => { bytes[index] = index; });
            return bytes;
        },
    },
    setTimeout(callback) {
        scheduled.push(callback);
        return scheduled.length;
    },
    clearTimeout() {},
    addEventListener: (type, listener) => windowListeners.set(type, listener),
};
const navigator = {
    sendBeacon(endpoint, body) {
        beaconRequests.push({endpoint, body: new URLSearchParams(body)});
        return beaconSucceeds;
    },
};
const fetch = async (endpoint, options) => {
    if (endpoint.endsWith('/leave')) {
        leaveFetches.push({endpoint, options, body: new URLSearchParams(options.body)});
        return {ok: true, status: 200, json: async () => ({left: true})};
    }
    requestBodies.push(new URLSearchParams(options.body));
    ++fetchAttempt;
    if (fetchAttempt === 1) {
        throw new Error('response lost');
    }
    return {
        ok: true,
        status: 200,
        json: async () => responsePayload,
    };
};

vm.runInNewContext(source, {
    Array,
    CustomEvent: class CustomEvent { constructor(type, options) { this.type = type; this.detail = options?.detail; } },
    Date,
    Error,
    Number,
    String,
    URLSearchParams,
    Uint8Array,
    document,
    fetch,
    navigator,
    window,
});

const flush = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setImmediate(resolve));
};

(async () => {
    await flush();
    assert.equal(randomCalls, 1, 'one nonce is generated for the document');
    assert.equal(requestBodies.length, 1);
    const firstNonce = requestBodies[0].get('player_instance_id');
    assert.match(firstNonce, /^[a-f0-9]{32}$/);

    scheduled.shift()();
    await flush();
    assert.equal(requestBodies[1].get('player_instance_id'), firstNonce,
        'a failed request retries the same nonce');

    scheduled.shift()();
    await flush();
    assert.equal(requestBodies[2].has('player_instance_id'), false,
        'a valid successful response completes registration');
    assert.equal(randomCalls, 1, 'ordinary polling does not create another instance');

    document.visibilityState = 'hidden';
    listeners.get('visibilitychange')();
    assert.equal(beaconRequests.length, 0, 'hiding or switching tabs does not send leave');
    assert.equal(leaveFetches.length, 0);

    windowListeners.get('pagehide')({persisted: false});
    windowListeners.get('pagehide')({persisted: false});
    assert.equal(beaconRequests.length, 1, 'pagehide sends leave exactly once');
    assert.equal(beaconRequests[0].endpoint, '/room/ABC/leave');
    assert.equal(beaconRequests[0].body.get('_token'), 'csrf');
    assert.equal(beaconRequests[0].body.get('player_instance_id'), firstNonce);
    assert.equal(leaveFetches.length, 0, 'successful sendBeacon needs no fallback');

    windowListeners.get('pageshow')({persisted: true});
    await flush();
    assert.equal(requestBodies.at(-1).get('player_instance_id'), firstNonce,
        'BFCache restore re-registers the same document instance');
    assert.equal(randomCalls, 1, 'BFCache restore does not generate a new nonce');

    beaconSucceeds = false;
    windowListeners.get('pagehide')({persisted: true});
    await flush();
    assert.equal(beaconRequests.length, 2);
    assert.equal(leaveFetches.length, 1, 'failed sendBeacon falls back to fetch');
    assert.equal(leaveFetches[0].options.method, 'POST');
    assert.equal(leaveFetches[0].options.keepalive, true);
    assert.equal(leaveFetches[0].options.credentials, 'same-origin');
    assert.equal(leaveFetches[0].body.get('_token'), 'csrf');
    assert.equal(leaveFetches[0].body.get('player_instance_id'), firstNonce);

    responsePayload = {participants: [], transmission: {
        source: 'iptv', instance_id: 'a'.repeat(32), youtube_video_id: null, revision: 8,
        owner_name: 'Bridge local', is_owner: false, media_mode: 'live',
        playback: {state: 'playing', position_ms: 0, revision: 1,
            at_live_edge: false, live_edge_position_ms: null,
            live_sync_position_ms: null, live_sync_delay_ms: null},
    }};
    scheduled.shift()();
    await flush();
    const iptvEvent = dispatched.filter((event) => event.type === 'semyra:presence-updated').at(-1);
    assert.equal(iptvEvent.detail.transmission.source, 'iptv');
    assert.equal(iptvEvent.detail.transmission.instanceId, 'a'.repeat(32));
    assert.equal(iptvEvent.detail.transmission.videoId, null);
    assert.equal(iptvEvent.detail.transmission.mediaMode, 'live');

    responsePayload.transmission.media_mode = 'vod';
    scheduled.shift()();
    await flush();
    const vodEvent = dispatched.filter((event) => event.type === 'semyra:presence-updated').at(-1);
    assert.equal(vodEvent.detail.transmission, null, 'IPTV VOD is not applicable in this stage');

    assert.equal(source.includes('localStorage'), false);
    assert.equal(source.includes('sessionStorage'), false);
    assert.equal(source.includes('document.cookie'), false);
    console.log('room-presence tests passed');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
