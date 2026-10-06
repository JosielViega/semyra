'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/js/room-desktop-host.js', 'utf8');
const token = 'a'.repeat(32) + '.' + 'b'.repeat(64);

const flush = () => new Promise((resolve) => setImmediate(resolve));
const transmission = (overrides = {}) => ({
    source: 'iptv',
    mediaMode: 'live',
    isOwner: true,
    instanceId: 'c'.repeat(32),
    revision: 3,
    ...overrides,
});

const createHarness = ({existingHost = false} = {}) => {
    const windowListeners = new Map();
    const documentListeners = new Map();
    const events = [];
    const requests = [];
    const shell = {dataset: {
        desktopHostSessionUrl: '/room/ROOM2345/desktop/host-session',
        csrfToken: 'csrf',
    }};
    class CustomEvent {
        constructor(type, options) { this.type = type; this.detail = options?.detail; }
    }
    const window = {
        location: {pathname: '/room/ROOM2345'},
        SemyraDesktopBridge: existingHost ? {getHostSnapshot: () => ({
            state: 'ready', capabilities: ['host.status', 'host.authorize'],
        })} : undefined,
        addEventListener(type, listener) { windowListeners.set(type, listener); },
        dispatchEvent(event) {
            events.push(event);
            windowListeners.get(event.type)?.(event);
        },
    };
    const document = {
        querySelector: (selector) => selector === '[data-room-shell]' ? shell : null,
        addEventListener(type, listener) { documentListeners.set(type, listener); },
        dispatchEvent(event) {
            events.push(event);
            documentListeners.get(event.type)?.(event);
        },
    };
    const fetch = async (url, options) => {
        const body = new URLSearchParams(options.body);
        requests.push({url, options, body});
        return {
            ok: true,
            status: 201,
            json: async () => ({
                host_session_token: token,
                expires_at: '2099-10-06T12:00:00.000Z',
                permission: 'media.publish',
                transmission_instance_id: body.get('transmission_instance_id'),
                transmission_revision: Number(body.get('transmission_revision')),
            }),
        };
    };
    vm.runInNewContext(source, {
        window, document, fetch, CustomEvent, URLSearchParams, Date, console,
    });

    return {
        events,
        requests,
        hostReady() {
            window.dispatchEvent(new CustomEvent('semyra:host-ready', {detail: {
                state: 'ready', capabilities: ['host.status', 'host.authorize'],
            }}));
        },
        presence(value) {
            document.dispatchEvent(new CustomEvent('semyra:presence-updated', {
                detail: {transmission: value},
            }));
        },
    };
};

test('normal browser and ineligible transmissions never request Host Session', async () => {
    const browser = createHarness();
    browser.presence(transmission());
    await flush();
    assert.equal(browser.requests.length, 0);

    const harness = createHarness();
    harness.hostReady();
    harness.presence(transmission({isOwner: false}));
    harness.presence(transmission({source: 'youtube'}));
    await flush();
    assert.equal(harness.requests.length, 0);
});

test('IPTV Live owner requests once per instance and revision', async () => {
    const harness = createHarness();
    harness.hostReady();
    harness.presence(transmission());
    await flush();
    harness.presence(transmission());
    await flush();

    assert.equal(harness.requests.length, 1);
    assert.equal(harness.requests[0].body.get('_token'), 'csrf');
    assert.equal(harness.requests[0].body.get('transmission_instance_id'), 'c'.repeat(32));
    const authorize = harness.events.find((event) => event.type === 'semyra:host-authorization-request');
    assert.equal(authorize.detail.hostSessionToken, token);
});

test('uses an already-ready Host snapshot when the event happened before script load', async () => {
    const harness = createHarness({existingHost: true});
    harness.presence(transmission());
    await flush();
    assert.equal(harness.requests.length, 1);
});

test('replacement clears and authorizes the new context', async () => {
    const harness = createHarness();
    harness.hostReady();
    harness.presence(transmission());
    await flush();
    harness.presence(transmission({instanceId: 'd'.repeat(32), revision: 1}));
    await flush();

    assert.equal(harness.requests.length, 2);
    assert.equal(harness.events.filter((event) => event.type === 'semyra:host-clear-request').length, 1);
    assert.equal(harness.requests[1].body.get('transmission_instance_id'), 'd'.repeat(32));
});

test('owner loss and null transmission clear authorization', async () => {
    for (const next of [transmission({isOwner: false}), null]) {
        const harness = createHarness();
        harness.hostReady();
        harness.presence(transmission());
        await flush();
        harness.presence(next);
        await flush();
        assert.equal(harness.events.some((event) => event.type === 'semyra:host-clear-request'), true);
    }
});
