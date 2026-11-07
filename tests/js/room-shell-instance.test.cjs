'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
    path.join(__dirname, '../../public/assets/js/room-shell.js'),
    'utf8',
);
const listeners = new Map();
const dispatched = [];
const endForm = {
    toggleAttribute() {},
    addEventListener(type, listener) { listeners.set(`end-form:${type}`, listener); },
};
const endInstance = {value: ''};
const endRevision = {value: ''};
const shell = {
    dataset: {
        initialSource: 'iptv',
        initialInstanceId: 'a'.repeat(32),
        initialVideoId: '',
        initialRevision: '1',
        initialOwnerName: 'Bridge',
        initialIsOwner: '1',
        initialMediaMode: 'live',
        initialPlaybackState: 'playing',
        initialPlaybackPositionMs: '',
        initialPlaybackRevision: '1',
        initialPlaybackAtLiveEdge: '0',
        initialLiveEdgePositionMs: '',
        initialLiveSyncPositionMs: '',
        initialLiveSyncDelayMs: '',
    },
    classList: {toggle() {}, remove() {}, add() {}, contains() { return false; }},
    querySelector(selector) {
        if (selector === '[data-end-transmission]') return endForm;
        if (selector === '[data-end-transmission-instance-id]') return endInstance;
        if (selector === '[data-end-transmission-revision]') return endRevision;
        return null;
    },
    querySelectorAll() { return []; },
};
const document = {
    fullscreenElement: null,
    querySelector: (selector) => selector === '[data-room-shell]' ? shell : null,
    getElementById() { return null; },
    addEventListener(type, listener) { listeners.set(type, listener); },
    dispatchEvent(event) { dispatched.push(event); listeners.get(event.type)?.(event); },
};
const window = {
    matchMedia: () => ({matches: true}),
    setTimeout() { return 1; },
    clearTimeout() {},
    addEventListener(type, listener) { listeners.set(type, listener); },
    dispatchEvent(event) { dispatched.push(event); listeners.get(event.type)?.(event); },
};
class CustomEvent {
    constructor(type, options) { this.type = type; this.detail = options?.detail; }
}
class Element {}
class HTMLDialogElement extends Element {}

vm.runInNewContext(source, {CustomEvent, Element, HTMLDialogElement, document, window});

const transmissionEvents = () => dispatched.filter(
    (event) => event.type === 'semyra:transmission-updated',
);
assert.equal(transmissionEvents().length, 1);
assert.equal(endInstance.value, 'a'.repeat(32));
assert.equal(endRevision.value, '1');

const replacement = {
    ...transmissionEvents()[0].detail.transmission,
    instanceId: 'b'.repeat(32),
};
listeners.get('semyra:presence-updated')({detail: {transmission: replacement, requestStartedAt: 1234}});

assert.equal(transmissionEvents().length, 2,
    'same revision and metadata in a new instance dispatches transmission-updated');
assert.equal(endInstance.value, 'b'.repeat(32), 'end form follows the current instance');
assert.equal(endRevision.value, '1');
assert.equal(transmissionEvents().at(-1).detail.requestStartedAt, 1234,
    'the shell preserves presence observation ordering metadata');

const accepted = {...replacement, instanceId: 'c'.repeat(32), revision: 2};
listeners.get('semyra:transmission-accepted')({detail: {transmission: accepted, acceptedAt: 2000}});
const acceptedEventCount = transmissionEvents().length;
listeners.get('semyra:presence-updated')({detail: {transmission: null, requestStartedAt: 1500}});
assert.equal(transmissionEvents().length, acceptedEventCount,
    'presence requested before local acceptance cannot clear the accepted transmission');
assert.equal(endInstance.value, 'c'.repeat(32));

listeners.get('end-form:submit')();
const ending = dispatched.filter((event) => event.type === 'semyra:transmission-ending');
assert.equal(ending.at(-1).detail.instanceId, 'c'.repeat(32));
assert.equal(ending.at(-1).detail.revision, 2);
assert.equal(endInstance.value, 'c'.repeat(32), 'local cleanup must not clear fenced form fields');
assert.equal(endRevision.value, '2');

listeners.get('semyra:presence-updated')({detail: {transmission: null, requestStartedAt: 2100}});
assert.equal(transmissionEvents().length, acceptedEventCount + 1,
    'a newer presence observation remains authoritative');
assert.equal(endInstance.value, '', 'newer authoritative presence clears fenced form fields');
assert.equal(endRevision.value, '');
console.log('room-shell instance tests passed');
