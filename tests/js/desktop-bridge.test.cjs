'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const desktop = require('../../public/assets/js/desktop-bridge.js');

test('desktop bridge account-scoped protocol', async () => {

const browserEvents = [];
const browserRemember = createCheckbox();
const browser = {
    dispatchEvent: (event) => browserEvents.push(event),
    document: {getElementById: () => browserRemember},
};
const browserBridge = desktop.createDesktopBridge(browser);
assert.equal(browserBridge.start(), false);
assert.equal(browserBridge.isReady(), false);
assert.equal(browserBridge.getHostSnapshot(), null);
assert.deepEqual(browserEvents, []);
assert.equal(browserRemember.checked, false);

let messageListener = null;
const posted = [];
const dispatched = [];
const windowListeners = new Map();
const desktopRemember = createCheckbox();
const webview = {
    addEventListener: (type, listener) => {
        assert.equal(type, 'message');
        messageListener = listener;
    },
    removeEventListener: (type, listener) => {
        assert.equal(type, 'message');
        assert.equal(listener, messageListener);
    },
    postMessage: (message) => posted.push(message),
};
class MockCustomEvent {
    constructor(type, options) {
        this.type = type;
        this.detail = options.detail;
    }
}
const webviewWindow = {
    chrome: {webview},
    crypto: {randomUUID: () => 'request-11a'},
    CustomEvent: MockCustomEvent,
    dispatchEvent: (event) => dispatched.push(event),
    addEventListener: (type, listener) => windowListeners.set(type, listener),
    document: {getElementById: () => desktopRemember},
    fetch: async () => ({ok: true, json: async () => ({profile_id: 'a'.repeat(64)})}),
};

const webviewBridge = desktop.createDesktopBridge(webviewWindow);
assert.equal(webviewBridge.start(), true);
assert.deepEqual(posted, [{type: 'semyra.desktop.ping', requestId: 'request-11a'}]);

messageListener({data: {type: 'unknown', requestId: 'request-11a'}});
assert.equal(webviewBridge.isReady(), false);
assert.deepEqual(dispatched, []);

messageListener({
    data: {
        type: 'semyra.desktop.pong',
        requestId: 'request-11a',
        protocolVersion: 1,
        desktop: true,
        platform: 'windows',
        appVersion: '1.0.0',
    },
});
await new Promise((resolve) => setImmediate(resolve));
assert.equal(webviewBridge.isReady(), true);
assert.equal(desktopRemember.checked, true);
assert.deepEqual(posted, [
    {type: 'semyra.desktop.ping', requestId: 'request-11a'},
    {type: 'semyra.desktop.account.activate', requestId: 'request-11a', profileId: 'a'.repeat(64)},
]);
messageListener({data: {
    type: 'semyra.desktop.account.activate-result', requestId: 'request-11a', protocolVersion: 1,
    activated: true, accountContextId: 'b'.repeat(32),
}});
assert.deepEqual(posted, [
    {type: 'semyra.desktop.ping', requestId: 'request-11a'},
    {type: 'semyra.desktop.account.activate', requestId: 'request-11a', profileId: 'a'.repeat(64)},
    {type: 'semyra.desktop.host.status', requestId: 'request-11a'},
]);
assert.equal(dispatched.length, 1);
assert.equal(dispatched[0].type, 'semyra:desktop-ready');
assert.deepEqual(dispatched[0].detail, {
    protocolVersion: 1,
    platform: 'windows',
    appVersion: '1.0.0',
});
assert.equal(webviewBridge.getHostSnapshot(), null);

messageListener({data: {
    type: 'semyra.desktop.host.status-result',
    requestId: 'wrong-request',
    protocolVersion: 1,
    host: {state: 'ready', capabilities: ['host.status']},
}});
messageListener({data: {
    type: 'semyra.desktop.host.status-result',
    requestId: 'request-11a',
    protocolVersion: 2,
    host: {state: 'ready', capabilities: ['host.status']},
}});
assert.equal(webviewBridge.getHostSnapshot(), null);
assert.equal(dispatched.length, 1);

messageListener({data: {
    type: 'semyra.desktop.host.status-result',
    requestId: 'request-11a',
    protocolVersion: 1,
    host: {
        state: 'ready',
        capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog', 'iptv.play', 'iptv.local-view'],
        authorization: {authorized: false},
    },
}});
assert.deepEqual(webviewBridge.getHostSnapshot(), {
    state: 'ready',
    capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog', 'iptv.play', 'iptv.local-view'],
    authorization: {
        authorized: false,
        permission: null,
        transmissionInstanceId: null,
        transmissionRevision: null,
    },
});
assert.equal(dispatched.length, 2);
assert.equal(dispatched[1].type, 'semyra:host-ready');
assert.deepEqual(dispatched[1].detail, {
    state: 'ready',
    capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog', 'iptv.play', 'iptv.local-view'],
    authorization: {
        authorized: false,
        permission: null,
        transmissionInstanceId: null,
        transmissionRevision: null,
    },
});

windowListeners.get('semyra:iptv-request')({detail: {action: 'list'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.sources.list',
    requestId: 'request-11a',
    accountContextId: 'b'.repeat(32),
});
messageListener({data: {
    type: 'semyra.desktop.iptv.sources.list-result',
    requestId: 'request-11a',
    protocolVersion: 1,
    ok: true,
    sources: [{
        id: 4, name: 'Local', type: 'm3u_file', enabled: true,
        lastRefreshStatus: 'ready', lastRefreshError: null, lastRefreshAt: null, channelCount: 3,
        location: 'must-not-cross',
    }],
}});
assert.equal(dispatched.at(-1).type, 'semyra:iptv-result');
assert.deepEqual(dispatched.at(-1).detail.sources, [{
    id: 4, name: 'Local', type: 'm3u_file', enabled: true,
    lastRefreshStatus: 'ready', lastRefreshError: null, lastRefreshAt: null, channelCount: 3,
}]);
assert.equal(JSON.stringify(dispatched.at(-1)).includes('must-not-cross'), false);

webviewWindow.crypto.randomUUID = () => 'media-start';
windowListeners.get('semyra:iptv-request')({detail: {
    action: 'media-start', channelId: 7, url: 'https://must-not-cross.example/live.ts',
}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.media.start', requestId: 'media-start', accountContextId: 'b'.repeat(32), channelId: 7,
});
assert.equal(JSON.stringify(posted.at(-1)).includes('must-not-cross'), false);

webviewWindow.crypto.randomUUID = () => 'view-start';
windowListeners.get('semyra:iptv-request')({detail: {
    action: 'view-start', channelId: 8, url: 'https://must-not-cross.example/live.ts',
}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.view.start', requestId: 'view-start', accountContextId: 'b'.repeat(32), channelId: 8,
});

for (const [action, type] of [
    ['media-stop', 'semyra.desktop.iptv.media.stop'],
    ['media-status', 'semyra.desktop.iptv.media.status'],
]) {
    webviewWindow.crypto.randomUUID = () => action;
    windowListeners.get('semyra:iptv-request')({detail: {action}});
    assert.deepEqual(posted.at(-1), {type, requestId: action, accountContextId: 'b'.repeat(32)});
}

messageListener({data: {
    type: 'semyra.desktop.iptv.media.state', protocolVersion: 1,
    state: 'streaming', channelId: 7, channelName: 'Canal', attempt: 1, errorCode: null, mode: 'local',
    accountContextId: 'b'.repeat(32),
    streamUrl: 'https://must-not-cross.example/live.ts',
}});
assert.equal(dispatched.at(-1).type, 'semyra:iptv-media-state');
assert.deepEqual(dispatched.at(-1).detail, {
    state: 'streaming', channelId: 7, channelName: 'Canal', attempt: 1, errorCode: null, mode: 'local',
});
assert.equal(JSON.stringify(dispatched.at(-1)).includes('must-not-cross'), false);

webviewWindow.crypto.randomUUID = () => 'request-11a';
windowListeners.get('semyra:iptv-request')({detail: {action: 'pick-file', path: 'C:\\private.m3u'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.sources.pick-file',
    requestId: 'request-11a',
    accountContextId: 'b'.repeat(32),
});

const hostToken = 'a'.repeat(32) + '.' + 'b'.repeat(64);
windowListeners.get('semyra:host-authorization-request')({detail: {
    hostSessionToken: hostToken,
    roomCode: 'ROOM2345',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
    permission: 'media.publish',
    expiresAt: '2099-10-06T12:00:00.000Z',
}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.host.authorize',
    requestId: 'request-11a',
    hostSessionToken: hostToken,
    roomCode: 'ROOM2345',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
    permission: 'media.publish',
    expiresAt: '2099-10-06T12:00:00.000Z',
});
messageListener({data: {
    type: 'semyra.desktop.host.authorize-result',
    requestId: 'wrong-request',
    protocolVersion: 1,
    authorized: true,
    permission: 'media.publish',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
}});
assert.equal(dispatched.length, 4);
messageListener({data: {
    type: 'semyra.desktop.host.authorize-result',
    requestId: 'request-11a',
    protocolVersion: 1,
    authorized: true,
    permission: 'media.publish',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
}});
assert.equal(dispatched[4].type, 'semyra:host-authorized');
assert.deepEqual(dispatched[4].detail, {
    authorized: true,
    permission: 'media.publish',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
});
assert.equal(JSON.stringify(dispatched[4]).includes(hostToken), false);
assert.equal(JSON.stringify(webviewBridge.getHostSnapshot()).includes(hostToken), false);

windowListeners.get('semyra:host-clear-request')({});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.host.clear',
    requestId: 'request-11a',
});
messageListener({data: {
    type: 'semyra.desktop.host.clear-result',
    requestId: 'request-11a',
    protocolVersion: 1,
    cleared: true,
}});
assert.equal(dispatched[5].type, 'semyra:host-cleared');
assert.equal(webviewBridge.getHostSnapshot().authorization.authorized, false);

webviewWindow.crypto.randomUUID = () => 'iptv-add';
windowListeners.get('semyra:iptv-request')({detail: {action: 'add-url', name: 'Fonte', location: 'https://private.example/list.m3u'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.sources.add', requestId: 'iptv-add', sourceType: 'm3u_url',
    accountContextId: 'b'.repeat(32),
    name: 'Fonte', location: 'https://private.example/list.m3u',
});

for (const [action, type] of [
    ['refresh', 'semyra.desktop.iptv.sources.refresh'],
    ['remove', 'semyra.desktop.iptv.sources.remove'],
    ['groups', 'semyra.desktop.iptv.groups.list'],
]) {
    webviewWindow.crypto.randomUUID = () => 'iptv-' + action;
    windowListeners.get('semyra:iptv-request')({detail: {action, sourceId: 4}});
    assert.deepEqual(posted.at(-1), {type, requestId: 'iptv-' + action, accountContextId: 'b'.repeat(32), sourceId: 4});
}

webviewWindow.crypto.randomUUID = () => 'iptv-search';
windowListeners.get('semyra:iptv-request')({detail: {
    action: 'search', sourceId: 4, query: 'news', group: 'Live', offset: 100, limit: 100,
}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.channels.search', requestId: 'iptv-search', sourceId: 4,
    accountContextId: 'b'.repeat(32),
    query: 'news', group: 'Live', offset: 100, limit: 100,
});
messageListener({data: {
    type: 'semyra.desktop.iptv.channels.search-result', requestId: 'iptv-search', protocolVersion: 1, ok: true,
    channels: [{id: 7, name: 'News', groupName: 'Live', logoUrl: null, tvgId: 'n1', streamUrl: 'must-not-cross'}],
    offset: 100, limit: 100, hasMore: true,
}});
assert.equal(dispatched.at(-1).type, 'semyra:iptv-result');
assert.equal(dispatched.at(-1).detail.offset, 100);
assert.equal(dispatched.at(-1).detail.hasMore, true);
assert.equal(JSON.stringify(dispatched.at(-1)).includes('must-not-cross'), false);

let interactedListener = null;
const interactedRemember = {
    checked: false,
    addEventListener: (type, listener) => {
        assert.equal(type, 'change');
        interactedListener = listener;
    },
};
let interactedMessage = null;
const interactedWindow = {
    chrome: {webview: {
        addEventListener: (type, listener) => {
            assert.equal(type, 'message');
            interactedMessage = listener;
        },
        removeEventListener: () => {},
        postMessage: () => {},
    }},
    crypto: {randomUUID: () => 'request-interacted'},
    CustomEvent: MockCustomEvent,
    dispatchEvent: () => {},
    document: {getElementById: () => interactedRemember},
};
const interactedBridge = desktop.createDesktopBridge(interactedWindow);
assert.equal(interactedBridge.start(), true);
interactedRemember.checked = false;
interactedListener();
interactedMessage({data: {
    type: 'semyra.desktop.pong',
    requestId: 'request-interacted',
    protocolVersion: 1,
    desktop: true,
    platform: 'windows',
}});
assert.equal(interactedRemember.checked, false);

const persistentDesktopStorage = createStorage();
const firstDesktopSession = createStorage();
let preferenceSubmit = null;
let preferenceChange = null;
const optOutRemember = createCheckbox();
optOutRemember.form = {
    addEventListener: (type, listener) => {
        assert.equal(type, 'submit');
        preferenceSubmit = listener;
    },
};
optOutRemember.addEventListener = (type, listener) => {
    assert.equal(type, 'change');
    preferenceChange = listener;
};
let preferenceMessage = null;
const preferenceWindow = {
    chrome: {webview: {
        addEventListener: (type, listener) => {
            assert.equal(type, 'message');
            preferenceMessage = listener;
        },
        removeEventListener: () => {},
        postMessage: () => {},
    }},
    crypto: {randomUUID: () => 'request-preference'},
    CustomEvent: MockCustomEvent,
    dispatchEvent: () => {},
    document: {getElementById: () => optOutRemember},
    localStorage: persistentDesktopStorage,
    sessionStorage: firstDesktopSession,
};
desktop.createDesktopBridge(preferenceWindow).start();
preferenceMessage({data: {
    type: 'semyra.desktop.pong',
    requestId: 'request-preference',
    protocolVersion: 1,
    desktop: true,
    platform: 'windows',
}});
assert.equal(optOutRemember.checked, true);
optOutRemember.checked = false;
preferenceChange();
preferenceSubmit();
assert.equal(persistentDesktopStorage.getItem('semyra.desktop.remember'), '0');
assert.equal(firstDesktopSession.getItem('semyra.desktop.session'), '1');

let restartMessage = null;
let logoutSubmits = 0;
const restartedWindow = {
    chrome: {webview: {
        addEventListener: (type, listener) => {
            assert.equal(type, 'message');
            restartMessage = listener;
        },
        removeEventListener: () => {},
        postMessage: () => {},
    }},
    crypto: {randomUUID: () => 'request-restart'},
    CustomEvent: MockCustomEvent,
    dispatchEvent: () => {},
    document: {
        getElementById: () => null,
        querySelector: (selector) => {
            assert.equal(selector, 'form.account-logout[action="/logout"]');
            return {requestSubmit: () => { logoutSubmits += 1; }};
        },
    },
    localStorage: persistentDesktopStorage,
    sessionStorage: createStorage(),
};
desktop.createDesktopBridge(restartedWindow).start();
restartMessage({data: {
    type: 'semyra.desktop.pong',
    requestId: 'request-restart',
    protocolVersion: 1,
    desktop: true,
    platform: 'windows',
}});
assert.equal(logoutSubmits, 1);
assert.equal(restartedWindow.sessionStorage.getItem('semyra.desktop.session'), '1');

let noPlayMessage = null;
const noPlayPosted = [];
const noPlayListeners = new Map();
const noPlayWindow = {
    chrome: {webview: {
        addEventListener: (type, listener) => { noPlayMessage = listener; },
        removeEventListener: () => {},
        postMessage: (message) => noPlayPosted.push(message),
    }},
    crypto: {randomUUID: () => 'no-play'},
    CustomEvent: MockCustomEvent,
    dispatchEvent: () => {},
    addEventListener: (type, listener) => noPlayListeners.set(type, listener),
    document: {getElementById: () => null, querySelectorAll: () => []},
};
desktop.createDesktopBridge(noPlayWindow).start();
noPlayMessage({data: {type: 'semyra.desktop.pong', requestId: 'no-play', protocolVersion: 1, desktop: true, platform: 'windows'}});
noPlayMessage({data: {
    type: 'semyra.desktop.host.status-result', requestId: 'no-play', protocolVersion: 1,
    host: {state: 'ready', capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog'], authorization: {authorized: false}},
}});
const beforeNoPlayRequest = noPlayPosted.length;
noPlayListeners.get('semyra:iptv-request')({detail: {action: 'media-start', channelId: 7}});
assert.equal(noPlayPosted.length, beforeNoPlayRequest);
noPlayListeners.get('semyra:iptv-request')({detail: {action: 'view-start', channelId: 7}});
assert.equal(noPlayPosted.length, beforeNoPlayRequest);

console.log('desktop-bridge tests passed');
});

test('desktop account resolution fails closed and logout fences stale IPTV results', async () => {
    async function harness(fetchResponse) {
        const posted = [];
        const dispatched = [];
        const listeners = new Map();
        let receive = null;
        let logout = null;
        const link = {hidden: true};
        const target = {
            chrome: {webview: {
                addEventListener: (_type, listener) => { receive = listener; },
                removeEventListener: () => {},
                postMessage: (message) => posted.push(message),
            }},
            crypto: {randomUUID: () => 'account-request'},
            CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
            dispatchEvent: (event) => dispatched.push(event),
            addEventListener: (type, listener) => listeners.set(type, listener),
            fetch: async () => fetchResponse,
            document: {
                getElementById: () => null,
                querySelectorAll: () => [link],
                querySelector: () => ({addEventListener: (_type, listener) => { logout = listener; }}),
            },
        };
        desktop.createDesktopBridge(target).start();
        receive({data: {type: 'semyra.desktop.pong', requestId: 'account-request', protocolVersion: 1, desktop: true, platform: 'windows'}});
        await new Promise((resolve) => setImmediate(resolve));
        return {posted, dispatched, listeners, receive, logout: (event) => logout(event), link};
    }

    for (const response of [
        {ok: false, status: 401, json: async () => ({error: 'authentication_required'})},
        {ok: true, status: 200, json: async () => ({profile_id: '../invalid'})},
    ]) {
        const guest = await harness(response);
        assert.equal(guest.posted.at(-1).type, 'semyra.desktop.account.clear');
        guest.receive({data: {type: 'semyra.desktop.account.clear-result', requestId: 'account-request', protocolVersion: 1, cleared: true}});
        assert.equal(guest.posted.at(-1).type, 'semyra.desktop.host.status');
        guest.receive({data: {type: 'semyra.desktop.host.status-result', requestId: 'account-request', protocolVersion: 1,
            host: {state: 'ready', capabilities: ['host.status', 'host.authorize'], authorization: {authorized: false}}}});
        assert.equal(guest.link.hidden, true);
    }

    const authenticated = await harness({ok: true, status: 200, json: async () => ({profile_id: 'a'.repeat(64)})});
    authenticated.receive({data: {type: 'semyra.desktop.account.activate-result', requestId: 'account-request', protocolVersion: 1,
        activated: true, accountContextId: 'b'.repeat(32)}});
    authenticated.receive({data: {type: 'semyra.desktop.host.status-result', requestId: 'account-request', protocolVersion: 1,
        host: {state: 'ready', capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog'], authorization: {authorized: false}}}});
    assert.equal(authenticated.link.hidden, false);
    authenticated.listeners.get('semyra:iptv-request')({detail: {action: 'list'}});
    const eventsBeforeLogout = authenticated.dispatched.length;
    let prevented = 0;
    authenticated.logout({preventDefault: () => { prevented += 1; }});
    assert.equal(prevented, 0);
    assert.equal(authenticated.posted.at(-1).type, 'semyra.desktop.account.clear');
    authenticated.receive({data: {type: 'semyra.desktop.iptv.sources.list-result', requestId: 'account-request', protocolVersion: 1,
        ok: true, sources: []}});
    assert.equal(authenticated.dispatched.length, eventsBeforeLogout);
});

function createCheckbox() {
    return {
        checked: false,
        form: null,
        addEventListener: () => {},
    };
}

function createStorage() {
    const values = new Map();

    return {
        getItem: (key) => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, String(value)),
    };
}
