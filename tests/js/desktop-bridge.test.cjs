'use strict';

const assert = require('node:assert/strict');
const desktop = require('../../public/assets/js/desktop-bridge.js');

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
assert.equal(webviewBridge.isReady(), true);
assert.equal(desktopRemember.checked, true);
assert.deepEqual(posted, [
    {type: 'semyra.desktop.ping', requestId: 'request-11a'},
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
        capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog'],
        authorization: {authorized: false},
    },
}});
assert.deepEqual(webviewBridge.getHostSnapshot(), {
    state: 'ready',
    capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog'],
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
    capabilities: ['host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog'],
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

windowListeners.get('semyra:iptv-request')({detail: {action: 'pick-file', path: 'C:\\private.m3u'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.sources.pick-file',
    requestId: 'request-11a',
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
assert.equal(dispatched.length, 3);
messageListener({data: {
    type: 'semyra.desktop.host.authorize-result',
    requestId: 'request-11a',
    protocolVersion: 1,
    authorized: true,
    permission: 'media.publish',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
}});
assert.equal(dispatched[3].type, 'semyra:host-authorized');
assert.deepEqual(dispatched[3].detail, {
    authorized: true,
    permission: 'media.publish',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
});
assert.equal(JSON.stringify(dispatched[3]).includes(hostToken), false);
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
assert.equal(dispatched[4].type, 'semyra:host-cleared');
assert.equal(webviewBridge.getHostSnapshot().authorization.authorized, false);

webviewWindow.crypto.randomUUID = () => 'iptv-add';
windowListeners.get('semyra:iptv-request')({detail: {action: 'add-url', name: 'Fonte', location: 'https://private.example/list.m3u'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.sources.add', requestId: 'iptv-add', sourceType: 'm3u_url',
    name: 'Fonte', location: 'https://private.example/list.m3u',
});

for (const [action, type] of [
    ['refresh', 'semyra.desktop.iptv.sources.refresh'],
    ['remove', 'semyra.desktop.iptv.sources.remove'],
    ['groups', 'semyra.desktop.iptv.groups.list'],
]) {
    webviewWindow.crypto.randomUUID = () => 'iptv-' + action;
    windowListeners.get('semyra:iptv-request')({detail: {action, sourceId: 4}});
    assert.deepEqual(posted.at(-1), {type, requestId: 'iptv-' + action, sourceId: 4});
}

webviewWindow.crypto.randomUUID = () => 'iptv-search';
windowListeners.get('semyra:iptv-request')({detail: {
    action: 'search', sourceId: 4, query: 'news', group: 'Live', offset: 100, limit: 100,
}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.channels.search', requestId: 'iptv-search', sourceId: 4,
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

console.log('desktop-bridge tests passed');

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
