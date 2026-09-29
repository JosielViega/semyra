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
let fetchAttempt = 0;
let randomCalls = 0;
const listeners = new Map();
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
const presenceContainer = {dataset: {presenceUrl: '/room/ABC/presence', csrfToken: 'csrf'}};
const document = {
    visibilityState: 'visible',
    querySelector: (selector) => selector === '[data-room-presence]' ? presenceContainer : null,
    getElementById: (id) => elements.get(id) ?? null,
    createDocumentFragment: element,
    createElement: element,
    addEventListener: (type, listener) => listeners.set(type, listener),
    dispatchEvent() {},
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
};
const fetch = async (_endpoint, options) => {
    requestBodies.push(new URLSearchParams(options.body));
    ++fetchAttempt;
    if (fetchAttempt === 1) {
        throw new Error('response lost');
    }
    return {
        ok: true,
        status: 200,
        json: async () => ({participants: [], transmission: null}),
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

    assert.equal(source.includes('localStorage'), false);
    assert.equal(source.includes('sessionStorage'), false);
    assert.equal(source.includes('document.cookie'), false);
    console.log('room-presence tests passed');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
