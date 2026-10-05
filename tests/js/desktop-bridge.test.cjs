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
    host: {state: 'ready', capabilities: ['host.status']},
}});
assert.deepEqual(webviewBridge.getHostSnapshot(), {
    state: 'ready',
    capabilities: ['host.status'],
});
assert.equal(dispatched.length, 2);
assert.equal(dispatched[1].type, 'semyra:host-ready');
assert.deepEqual(dispatched[1].detail, {
    state: 'ready',
    capabilities: ['host.status'],
});

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
