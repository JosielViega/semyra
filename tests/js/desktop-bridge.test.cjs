'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const desktop = require('../../public/assets/js/desktop-bridge.js');
const real11iCapabilities = [
    'host.status', 'host.authorize', 'iptv.sources', 'iptv.catalog',
    'iptv.play', 'iptv.local-view', 'media.whip', 'livekit.publish',
];

test('desktop bridge account-scoped protocol', async () => {

const browserEvents = [];
const browserRemember = createCheckbox();
const browserIptvLink = {hidden: true};
const browser = {
    dispatchEvent: (event) => browserEvents.push(event),
    document: {getElementById: () => browserRemember, querySelectorAll: () => [browserIptvLink]},
};
const browserBridge = desktop.createDesktopBridge(browser);
assert.equal(browserBridge.start(), false);
assert.equal(browserBridge.isReady(), false);
assert.equal(browserBridge.getHostSnapshot(), null);
assert.deepEqual(browserEvents, []);
assert.equal(browserRemember.checked, false);
assert.equal(browserIptvLink.hidden, true, 'a common browser must keep Minha IPTV hidden');

let messageListener = null;
const posted = [];
const dispatched = [];
const windowListeners = new Map();
const bridgeTimers = new Map();
let bridgeTimerSequence = 0;
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
    setTimeout: (callback) => { bridgeTimerSequence += 1; bridgeTimers.set(bridgeTimerSequence, callback); return bridgeTimerSequence; },
    clearTimeout: (timerId) => bridgeTimers.delete(timerId),
    document: {getElementById: () => desktopRemember},
    fetch: async () => ({ok: true, json: async () => ({profile_id: 'a'.repeat(64)})}),
};

const webviewBridge = desktop.createDesktopBridge(webviewWindow);
assert.equal(webviewBridge.start(), true);
assert.equal(desktop.validCapabilities(real11iCapabilities), true);
assert.equal(desktop.validCapabilities([...real11iCapabilities, 'iptv.favorites']), false);
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
webviewWindow.crypto.randomUUID = () => 'player-stop';
windowListeners.get('semyra:iptv-request')({detail: {
    action: 'player-control', sessionId: 'c'.repeat(32), command: 'stop',
}});
assert.equal(posted.at(-1).command, 'stop');
assert.equal(posted.at(-1).sessionId, 'c'.repeat(32));
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

webviewWindow.crypto.randomUUID = () => 'iptv-favorites';
windowListeners.get('semyra:iptv-request')({detail: {action: 'favorites', sourceId: 4, offset: 0, limit: 50, clientRequestId: 'favorites-1'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.favorites.list', requestId: 'iptv-favorites', accountContextId: 'b'.repeat(32),
    sourceId: 4, offset: 0, limit: 50,
});
messageListener({data: {
    type: 'semyra.desktop.iptv.favorites.list-result', requestId: 'iptv-favorites', protocolVersion: 1, ok: true,
    channels: [{id: 7, name: 'News', groupName: 'Live', isFavorite: true, stableKey: 'must-not-cross', logicalKey: 'must-not-cross'}],
    offset: 0, limit: 50, hasMore: false,
}});
assert.equal(dispatched.at(-1).detail.channels[0].isFavorite, true);
assert.equal(JSON.stringify(dispatched.at(-1)).includes('must-not-cross'), false);
assert.equal(bridgeTimers.size, 0, 'favorites success must clear its response timeout');

webviewWindow.crypto.randomUUID = () => 'iptv-recents';
windowListeners.get('semyra:iptv-request')({detail: {action: 'recents', sourceId: 4, clientRequestId: 'recents-1'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.recents.list', requestId: 'iptv-recents', accountContextId: 'b'.repeat(32), sourceId: 4,
});
messageListener({data: {
    type: 'semyra.desktop.iptv.recents.list-result', requestId: 'iptv-recents', protocolVersion: 1, ok: true,
    channels: [{recentId: 17, channelId: 7, name: 'News', groupName: 'Live', logoUrl: null, tvgId: 'n1', isFavorite: false}],
}});
assert.equal(dispatched.at(-1).detail.action, 'recents');
assert.deepEqual(dispatched.at(-1).detail.channels, [{recentId: 17, id: 7, name: 'News', groupName: 'Live', logoUrl: null, tvgId: 'n1', isFavorite: false}]);
assert.equal(bridgeTimers.size, 0, 'recents success must clear its response timeout');

webviewWindow.crypto.randomUUID = () => 'iptv-favorite-set';
windowListeners.get('semyra:iptv-request')({detail: {action: 'favorite-set', channelId: 7, favorite: false, clientRequestId: 'favorite-1'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.favorite.set', requestId: 'iptv-favorite-set', accountContextId: 'b'.repeat(32),
    channelId: 7, favorite: false,
});
messageListener({data: {
    type: 'semyra.desktop.iptv.favorite.set-result', requestId: 'iptv-favorite-set', protocolVersion: 1, ok: true,
    channelId: 7, favorite: false,
}});
assert.equal(dispatched.at(-1).detail.clientRequestId, 'favorite-1');
assert.equal(dispatched.at(-1).detail.favorite, false);
assert.equal(bridgeTimers.size, 0, 'favorite success must clear its response timeout');

webviewWindow.crypto.randomUUID = () => 'iptv-recent-remove';
windowListeners.get('semyra:iptv-request')({detail: {action: 'recent-remove', sourceId: 4, recentId: 17, clientRequestId: 'recent-remove-1'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.recent.remove', requestId: 'iptv-recent-remove', accountContextId: 'b'.repeat(32), sourceId: 4, recentId: 17,
});
messageListener({data: {
    type: 'semyra.desktop.iptv.recent.remove-result', requestId: 'iptv-recent-remove', protocolVersion: 1, ok: true,
    sourceId: 4, recentId: 17, removed: true,
}});
assert.equal(dispatched.at(-1).detail.removed, true);
assert.equal(bridgeTimers.size, 0, 'recent remove success must clear its response timeout');

webviewWindow.crypto.randomUUID = () => 'iptv-recents-clear';
windowListeners.get('semyra:iptv-request')({detail: {action: 'recents-clear', sourceId: 4, clientRequestId: 'recents-clear-1'}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.recents.clear', requestId: 'iptv-recents-clear', accountContextId: 'b'.repeat(32), sourceId: 4,
});
messageListener({data: {
    type: 'semyra.desktop.iptv.recents.clear-result', requestId: 'iptv-recents-clear', protocolVersion: 1, ok: true,
    sourceId: 4, removedCount: 3,
}});
assert.equal(dispatched.at(-1).detail.removedCount, 3);
assert.equal(bridgeTimers.size, 0, 'recents clear success must clear its response timeout');

webviewWindow.crypto.randomUUID = () => 'iptv-favorite-true';
windowListeners.get('semyra:iptv-request')({detail: {action: 'favorite-set', channelId: 7, favorite: true, clientRequestId: 'favorite-true'}});
assert.equal(posted.at(-1).favorite, true);
messageListener({data: {
    type: 'semyra.desktop.iptv.favorite.set-result', requestId: 'iptv-favorite-true', protocolVersion: 1, ok: false,
    errorCode: 'favorite_write_failed',
}});
assert.equal(dispatched.at(-1).detail.ok, false);
assert.equal(dispatched.at(-1).detail.error, 'favorite_write_failed');
assert.equal(bridgeTimers.size, 0, 'favorite error must clear its response timeout');

webviewWindow.crypto.randomUUID = () => 'iptv-timeout';
windowListeners.get('semyra:iptv-request')({detail: {action: 'favorites', sourceId: 4, offset: 0, limit: 50, clientRequestId: 'favorites-timeout'}});
assert.equal(bridgeTimers.size, 1);
const timeoutCallback = Array.from(bridgeTimers.values())[0];
timeoutCallback();
assert.equal(bridgeTimers.size, 0);
assert.deepEqual(dispatched.at(-1).detail, {
    action: 'favorites', ok: false, error: 'desktop_bridge_timeout', clientRequestId: 'favorites-timeout',
});
const dispatchedAfterTimeout = dispatched.length;
messageListener({data: {
    type: 'semyra.desktop.iptv.favorites.list-result', requestId: 'iptv-timeout', protocolVersion: 1, ok: true, channels: [],
}});
assert.equal(dispatched.length, dispatchedAfterTimeout, 'late response must not revive an expired pending request');

webviewWindow.crypto.randomUUID = () => 'recent-remove-timeout';
windowListeners.get('semyra:iptv-request')({detail: {action: 'recent-remove', sourceId: 4, recentId: 17, clientRequestId: 'recent-remove-timeout-client'}});
assert.equal(bridgeTimers.size, 1);
Array.from(bridgeTimers.values())[0]();
assert.deepEqual(dispatched.at(-1).detail, {
    action: 'recent-remove', ok: false, error: 'desktop_bridge_timeout', clientRequestId: 'recent-remove-timeout-client',
});
assert.equal(bridgeTimers.size, 0);

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
messageListener({data: {
    type: 'semyra.desktop.iptv.view.start-result', requestId: 'view-start', protocolVersion: 1,
    ok: true, state: 'loading', channelId: 8, channelName: 'Video', attempt: 1, errorCode: null,
    mode: 'view', playbackKind: 'native-mpv', sessionId: 'c'.repeat(32),
}});
assert.equal(dispatched.at(-1).detail.playbackKind, 'native-mpv');
assert.equal(dispatched.at(-1).detail.sessionId, 'c'.repeat(32));
assert.equal(JSON.stringify(dispatched.at(-1)).includes('example.invalid'), false);

webviewWindow.crypto.randomUUID = () => 'player-control';
windowListeners.get('semyra:iptv-request')({detail: {
    action: 'player-control', sessionId: 'c'.repeat(32), command: 'seek-relative', value: 30,
}});
assert.deepEqual(posted.at(-1), {
    type: 'semyra.desktop.iptv.player.control', requestId: 'player-control', accountContextId: 'b'.repeat(32),
    sessionId: 'c'.repeat(32), command: 'seek-relative', value: 30,
});

webviewWindow.crypto.randomUUID = () => 'player-geometry';
windowListeners.get('semyra:iptv-request')({detail: {
    action: 'player-geometry', sessionId: 'c'.repeat(32), x: 10, y: 20, width: 800, height: 450,
    viewportWidth: 1200, viewportHeight: 800, visible: true,
}});
assert.equal(posted.at(-1).type, 'semyra.desktop.player.geometry');

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
    playbackKind: null, sessionId: null, position: 0, duration: 0, seekable: false,
    muted: false, volume: 100, container: null, videoCodec: null, audioCodec: null, mediaKind: null, fullscreen: false, paused: false, buffering: false,
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
const dispatchedBeforeWrongAuthorization = dispatched.length;
messageListener({data: {
    type: 'semyra.desktop.host.authorize-result',
    requestId: 'wrong-request',
    protocolVersion: 1,
    authorized: true,
    permission: 'media.publish',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
}});
assert.equal(dispatched.length, dispatchedBeforeWrongAuthorization);
messageListener({data: {
    type: 'semyra.desktop.host.authorize-result',
    requestId: 'request-11a',
    protocolVersion: 1,
    authorized: true,
    permission: 'media.publish',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
}});
assert.equal(dispatched.at(-1).type, 'semyra:host-authorized');
assert.deepEqual(dispatched.at(-1).detail, {
    authorized: true,
    permission: 'media.publish',
    transmissionInstanceId: 'c'.repeat(32),
    transmissionRevision: 3,
});
assert.equal(JSON.stringify(dispatched.at(-1)).includes(hostToken), false);
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
assert.equal(dispatched.at(-1).type, 'semyra:host-cleared');
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
    clientRequestId: 'search-42',
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
assert.equal(dispatched.at(-1).detail.clientRequestId, 'search-42');
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

const eventsBeforeNativeActivity = dispatched.length;
const postsBeforeNativeActivity = posted.length;
messageListener({data: {type: 'semyra.desktop.player.user-activity'}});
assert.equal(dispatched.length, eventsBeforeNativeActivity + 1);
assert.equal(dispatched.at(-1).type, 'semyra:player-user-activity');
assert.equal(dispatched.at(-1).detail, undefined);
assert.equal(posted.length, postsBeforeNativeActivity, 'native activity is unilateral and creates no request');

assert.equal(desktop.PLAYER_CONTROLS_VISIBILITY_TYPE, 'semyra.desktop.player.controls-visibility');
const postsBeforeVisibility = posted.length;
windowListeners.get('semyra:player-controls-visibility')({detail: {hidden: true}});
assert.deepEqual(posted.at(-1), {type: 'semyra.desktop.player.controls-visibility', hidden: true});
assert.equal(Object.hasOwn(posted.at(-1), 'requestId'), false);
windowListeners.get('semyra:player-controls-visibility')({detail: {hidden: 'true'}});
assert.equal(posted.length, postsBeforeVisibility + 1, 'invalid visibility payload must fail closed');

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

    const authenticatedWithoutIptv = await harness({ok: true, status: 200, json: async () => ({profile_id: 'c'.repeat(64)})});
    authenticatedWithoutIptv.receive({data: {type: 'semyra.desktop.account.activate-result', requestId: 'account-request', protocolVersion: 1,
        activated: true, accountContextId: 'd'.repeat(32)}});
    authenticatedWithoutIptv.receive({data: {type: 'semyra.desktop.host.status-result', requestId: 'account-request', protocolVersion: 1,
        host: {state: 'ready', capabilities: ['host.status', 'host.authorize'], authorization: {authorized: false}}}});
    assert.equal(authenticatedWithoutIptv.link.hidden, true, 'an authenticated Desktop without IPTV capability must keep the link hidden');

    const authenticated = await harness({ok: true, status: 200, json: async () => ({profile_id: 'a'.repeat(64)})});
    authenticated.receive({data: {type: 'semyra.desktop.account.activate-result', requestId: 'account-request', protocolVersion: 1,
        activated: true, accountContextId: 'b'.repeat(32)}});
    authenticated.receive({data: {type: 'semyra.desktop.host.status-result', requestId: 'account-request', protocolVersion: 1,
        host: {state: 'ready', capabilities: real11iCapabilities, authorization: {authorized: false}}}});
    assert.equal(authenticated.link.hidden, false);
    assert.equal(authenticated.dispatched.at(-1).type, 'semyra:host-ready');
    assert.deepEqual(authenticated.dispatched.at(-1).detail.capabilities, real11iCapabilities);
    authenticated.listeners.get('semyra:iptv-request')({detail: {action: 'list'}});
    const eventsBeforeLogout = authenticated.dispatched.length;
    let prevented = 0;
    authenticated.logout({preventDefault: () => { prevented += 1; }});
    assert.equal(prevented, 0);
    assert.equal(authenticated.posted.at(-1).type, 'semyra.desktop.account.clear');
    assert.equal(authenticated.dispatched.length, eventsBeforeLogout + 1, 'logout must settle pending IPTV requests');
    assert.equal(authenticated.dispatched.at(-1).detail.error, 'account_context_changed');
    const eventsAfterLogoutFence = authenticated.dispatched.length;
    authenticated.receive({data: {type: 'semyra.desktop.iptv.sources.list-result', requestId: 'account-request', protocolVersion: 1,
        ok: true, sources: []}});
    assert.equal(authenticated.dispatched.length, eventsAfterLogoutFence);

    const guestAfterLogoutNavigation = await harness({ok: false, status: 401, json: async () => ({error: 'authentication_required'})});
    assert.equal(guestAfterLogoutNavigation.link.hidden, true, 'a fresh guest page after logout must start fail-closed');

    const activationFailure = await harness({ok: true, status: 200, json: async () => ({profile_id: 'e'.repeat(64)})});
    activationFailure.receive({data: {
        type: 'semyra.desktop.account.activate-result', requestId: 'account-request', protocolVersion: 1,
        activated: false, errorCode: 'account_context_unavailable',
    }});
    assert.equal(activationFailure.posted.at(-1).type, 'semyra.desktop.account.clear');
    assert.equal(activationFailure.link.hidden, true);
    assert.equal(activationFailure.dispatched.some((event) => event.type === 'semyra:host-ready'), false);
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
