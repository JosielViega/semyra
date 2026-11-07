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

const createHarness = ({existingHost = false, existingAuthorization = null, expiresAt = '2099-10-06T12:00:00.000Z', timers = false, failRequests = 0, errorCode = null} = {}) => {
    const windowListeners = new Map();
    const documentListeners = new Map();
    const events = [];
    const requests = [];
    const scheduled = [];
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
            state: 'ready', capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog'],
            authorization: existingAuthorization,
        })} : undefined,
        addEventListener(type, listener) { windowListeners.set(type, listener); },
        dispatchEvent(event) {
            events.push(event);
            windowListeners.get(event.type)?.(event);
        },
        setTimeout: timers ? ((callback, delay) => { scheduled.push({callback, delay}); return scheduled.length; }) : undefined,
        clearTimeout() {},
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
        if (requests.length <= failRequests) {
            throw new Error('synthetic failure');
        }
        if (errorCode !== null) {
            return {
                ok: false,
                status: 409,
                json: async () => ({error: errorCode}),
            };
        }
        return {
            ok: true,
            status: 201,
            json: async () => ({
                host_session_token: token,
                expires_at: expiresAt,
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
        scheduled,
        hostReady(authorization = null) {
            window.dispatchEvent(new CustomEvent('semyra:host-ready', {detail: {
                state: 'ready', capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog'],
                authorization,
            }}));
        },
        presence(value) {
            document.dispatchEvent(new CustomEvent('semyra:transmission-updated', {
                detail: {transmission: value, requestStartedAt: Date.now()},
            }));
        },
        ending(value) {
            document.dispatchEvent(new CustomEvent('semyra:transmission-ending', {detail: value}));
        },
        pagehide() {
            window.dispatchEvent(new CustomEvent('pagehide'));
        },
        cleared(value, cleared = true) {
            window.dispatchEvent(new CustomEvent('semyra:host-cleared', {detail: {
                cleared,
                transmissionInstanceId: value.instanceId,
                transmissionRevision: value.revision,
            }}));
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
    harness.presence(transmission({source: 'screen'}));
    await flush();
    assert.equal(harness.requests.length, 0);
});

test('renews approximately sixty seconds before expiry without clearing the active publish', async () => {
    const expiresAt = new Date(Date.now() + 120000).toISOString();
    const harness = createHarness({expiresAt, timers: true});
    harness.hostReady();
    harness.presence(transmission());
    await flush();

    assert.equal(harness.scheduled.length, 1);
    assert.ok(harness.scheduled[0].delay >= 59000 && harness.scheduled[0].delay <= 61000);
    harness.scheduled[0].callback();
    await flush();
    assert.equal(harness.requests.length, 2);
    assert.equal(harness.events.filter((event) => event.type === 'semyra:host-clear-request').length, 0);
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

test('IPTV VOD owner receives the same fenced Host Session flow', async () => {
    const harness = createHarness();
    harness.hostReady();
    harness.presence(transmission({mediaMode: 'vod'}));
    await flush();

    assert.equal(harness.requests.length, 1);
    assert.equal(harness.requests[0].body.get('transmission_revision'), '3');
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
    const clear = harness.events.find((event) => event.type === 'semyra:host-clear-request');
    assert.equal(clear.detail.transmissionInstanceId, 'c'.repeat(32));
    assert.equal(clear.detail.transmissionRevision, 3);
    assert.equal(harness.requests[1].body.get('transmission_instance_id'), 'd'.repeat(32));
});

test('replacement during retry backoff requests the new context immediately', async () => {
    const harness = createHarness({timers: true, failRequests: 1});
    harness.hostReady();
    harness.presence(transmission());
    await flush();
    assert.equal(harness.requests.length, 1);
    assert.equal(harness.scheduled.length, 1);
    const retry = harness.events.find((event) => event.type === 'semyra:host-session-retry');
    assert.equal(retry.detail.errorCode, 'host_session_request_failed');
    assert.equal(retry.detail.retryAttempt, 1);
    assert.equal(retry.detail.retryDelayMs, 5000);

    harness.presence(transmission({instanceId: 'd'.repeat(32), revision: 1}));
    await flush();

    assert.equal(harness.requests.length, 2);
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
        const clear = harness.events.find((event) => event.type === 'semyra:host-clear-request');
        assert.equal(clear.detail.transmissionInstanceId, 'c'.repeat(32));
        assert.equal(clear.detail.transmissionRevision, 3);
    }
});

test('owner end submit clears native authorization before backend navigation completes', async () => {
    const harness = createHarness();
    harness.hostReady();
    harness.presence(transmission());
    await flush();

    harness.ending({instanceId: 'c'.repeat(32), revision: 3});
    await flush();

    const clear = harness.events.find((event) => event.type === 'semyra:host-clear-request');
    assert.equal(clear.detail.transmissionInstanceId, 'c'.repeat(32));
    assert.equal(clear.detail.transmissionRevision, 3);
});

test('end navigation schedules the native room-leave fallback until scoped cleanup is confirmed', async () => {
    const pending = createHarness();
    pending.hostReady();
    pending.presence(transmission());
    await flush();
    pending.ending({instanceId: 'c'.repeat(32), revision: 3});
    pending.pagehide();

    assert.equal(pending.events.filter((event) => event.type === 'semyra:host-clear-request').length, 1);
    assert.equal(pending.events.filter((event) => event.type === 'semyra:host-leave-request').length, 1,
        'navigation before the native clear acknowledgement must retain the bounded cleanup fallback');

    const confirmed = createHarness();
    confirmed.hostReady();
    confirmed.presence(transmission());
    await flush();
    confirmed.ending({instanceId: 'c'.repeat(32), revision: 3});
    confirmed.cleared(transmission());
    confirmed.pagehide();

    assert.equal(confirmed.events.filter((event) => event.type === 'semyra:host-leave-request').length, 0,
        'a confirmed scoped clear must not schedule redundant room-leave cleanup');
});

test('fresh room controller reconciles a residual native IPTV authorization from the previous document', async () => {
    const harness = createHarness({
        existingHost: true,
        existingAuthorization: {
            authorized: true,
            permission: 'media.publish',
            transmissionInstanceId: 'c'.repeat(32),
            transmissionRevision: 3,
        },
    });

    harness.presence(null);
    await flush();

    const clear = harness.events.find((event) => event.type === 'semyra:host-clear-request');
    assert.equal(clear.detail.transmissionInstanceId, 'c'.repeat(32));
    assert.equal(clear.detail.transmissionRevision, 3);
    assert.equal(harness.requests.length, 0);

    const delayed = createHarness();
    delayed.presence(null);
    delayed.hostReady({
        authorized: true,
        permission: 'media.publish',
        transmissionInstanceId: 'd'.repeat(32),
        transmissionRevision: 4,
    });
    await flush();

    const delayedClear = delayed.events.find((event) => event.type === 'semyra:host-clear-request');
    assert.equal(delayedClear.detail.transmissionInstanceId, 'd'.repeat(32));
    assert.equal(delayedClear.detail.transmissionRevision, 4);
});

test('page leave schedules native publisher cleanup only for an eligible IPTV owner', async () => {
    const harness = createHarness();
    harness.hostReady();
    harness.presence(transmission());
    await flush();
    harness.pagehide();
    assert.equal(harness.events.at(-1).type, 'semyra:host-leave-request');

    const viewer = createHarness();
    viewer.hostReady();
    viewer.presence(transmission({isOwner: false}));
    viewer.pagehide();
    assert.equal(viewer.events.some((event) => event.type === 'semyra:host-leave-request'), false);
});

test('forwards only the sanitized Host Session error code to the room UI', async () => {
    const harness = createHarness({errorCode: 'transmission_changed'});
    harness.hostReady();
    harness.presence(transmission());
    await flush();

    const error = harness.events.find((event) => event.type === 'semyra:host-session-error');
    assert.equal(error.detail.errorCode, 'transmission_changed');
    assert.equal(error.detail.transmissionInstanceId, 'c'.repeat(32));
    assert.equal(error.detail.transmissionRevision, 3);
    assert.equal(Object.keys(error.detail).length, 3);

    const unsafe = createHarness({errorCode: 'internal detail must not cross the bridge'});
    unsafe.hostReady();
    unsafe.presence(transmission());
    await flush();
    const invalid = unsafe.events.find((event) => event.type === 'semyra:host-session-error');
    assert.equal(invalid.detail.errorCode, 'host_session_invalid_response');
    assert.equal(unsafe.scheduled.length, 0, 'terminal HTTP responses must not create hidden retries');
});

test('Host Session milestones are sanitized and correlated only by transmission context', async () => {
    const harness = createHarness();
    harness.hostReady();
    harness.presence(transmission());
    await flush();

    const stages = harness.events
        .filter((event) => event.type === 'semyra:host-session-stage')
        .map((event) => event.detail.stage);
    assert.deepEqual(stages, ['host_session_requested', 'host_session_received', 'desktop_authorization_requested']);
    const details = harness.events.filter((event) => event.type === 'semyra:host-session-stage').map((event) => event.detail);
    assert.ok(details.every((detail) => Object.keys(detail).sort().join(',') === 'stage,transmissionInstanceId,transmissionRevision'));
});
