'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
    path.join(__dirname, '../../public/assets/js/room-shell.js'),
    'utf8',
);

function createHarness(hostSnapshot = undefined) {
    const documentListeners = new Map();
    const windowListeners = new Map();
    const windowEvents = [];
    let browserEnterCount = 0;
    let browserExitCount = 0;

    const maximizeIcon = {hidden: false, toggleAttribute(_name, hidden) { this.hidden = hidden; }};
    const minimizeIcon = {hidden: true, toggleAttribute(_name, hidden) { this.hidden = hidden; }};
    const fullscreenButton = {
        dataset: {},
        attributes: new Map(),
        listener: null,
        querySelector(selector) {
            if (selector === '[data-icon-maximize]') return maximizeIcon;
            if (selector === '[data-icon-minimize]') return minimizeIcon;
            return null;
        },
        addEventListener(type, listener) {
            if (type === 'click') this.listener = listener;
        },
        setAttribute(name, value) { this.attributes.set(name, value); },
    };
    const shell = {
        dataset: {},
        classList: {toggle() {}, remove() {}, add() {}, contains() { return false; }},
        querySelector(selector) {
            return selector === '[data-fullscreen-toggle]' ? fullscreenButton : null;
        },
        querySelectorAll() { return []; },
        async requestFullscreen() {
            browserEnterCount += 1;
            document.fullscreenElement = shell;
        },
    };
    const document = {
        fullscreenElement: null,
        querySelector: (selector) => selector === '[data-room-shell]' ? shell : null,
        getElementById: () => null,
        addEventListener(type, listener) { documentListeners.set(type, listener); },
        dispatchEvent(event) { documentListeners.get(event.type)?.(event); },
        async exitFullscreen() {
            browserExitCount += 1;
            this.fullscreenElement = null;
        },
    };
    class CustomEvent {
        constructor(type, options) { this.type = type; this.detail = options?.detail; }
    }
    const window = {
        matchMedia: () => ({matches: true}),
        setTimeout: () => 1,
        clearTimeout() {},
        addEventListener(type, listener) { windowListeners.set(type, listener); },
        dispatchEvent(event) {
            windowEvents.push(event);
            windowListeners.get(event.type)?.(event);
        },
    };
    if (hostSnapshot !== undefined) {
        window.SemyraDesktopBridge = {
            isReady: () => true,
            getHostSnapshot: () => hostSnapshot,
        };
    }
    class Element {
        constructor(interactive = false) { this.interactive = interactive; }
        closest() { return this.interactive ? this : null; }
    }
    class HTMLDialogElement extends Element {}

    vm.runInNewContext(source, {CustomEvent, Element, HTMLDialogElement, document, window});

    return {
        document,
        fullscreenButton,
        maximizeIcon,
        minimizeIcon,
        window,
        windowEvents,
        click: () => fullscreenButton.listener(),
        keydown: (key, target = undefined) => {
            let prevented = false;
            documentListeners.get('keydown')({
                key,
                target,
                repeat: false,
                ctrlKey: false,
                altKey: false,
                metaKey: false,
                preventDefault() { prevented = true; },
            });
            return prevented;
        },
        interactiveTarget: new Element(true),
        browserEnterCount: () => browserEnterCount,
        browserExitCount: () => browserExitCount,
    };
}

test('common browser uses the DOM Fullscreen API and reflects its real state', async () => {
    const harness = createHarness();
    await harness.click();
    assert.equal(harness.browserEnterCount(), 1);
    assert.equal(harness.windowEvents.some((event) => event.type === 'semyra:window-fullscreen-request'), false);

    harness.document.dispatchEvent({type: 'fullscreenchange'});
    assert.equal(harness.fullscreenButton.attributes.get('data-state'), 'fullscreen');
    assert.equal(harness.fullscreenButton.attributes.get('aria-label'), 'Sair da tela cheia');
    assert.equal(harness.maximizeIcon.hidden, true);
    assert.equal(harness.minimizeIcon.hidden, false);

    await harness.click();
    assert.equal(harness.browserExitCount(), 1);
});

test('Desktop capability routes button, state and Escape through the native bridge', async () => {
    const harness = createHarness({capabilities: ['host.status', 'host.authorize', 'window.fullscreen']});
    assert.equal(harness.windowEvents.at(-1).type, 'semyra:window-fullscreen-request');
    assert.equal(harness.windowEvents.at(-1).detail, undefined, 'initial request queries native state');

    harness.window.dispatchEvent(new harness.windowEvents[0].constructor(
        'semyra:window-fullscreen-state',
        {detail: {ok: true, enabled: true}},
    ));
    assert.equal(harness.fullscreenButton.attributes.get('data-state'), 'fullscreen');
    assert.equal(harness.fullscreenButton.attributes.get('aria-label'), 'Sair da tela cheia');

    const beforeClick = harness.windowEvents.length;
    await harness.click();
    assert.equal(harness.windowEvents.length, beforeClick + 1);
    assert.equal(harness.windowEvents.at(-1).detail.enabled, false);
    assert.equal(harness.browserEnterCount(), 0);

    const beforeEscape = harness.windowEvents.length;
    harness.keydown('Escape');
    assert.equal(harness.windowEvents.length, beforeEscape + 1);
    assert.equal(harness.windowEvents.at(-1).detail.enabled, false);

    const beforeShortcut = harness.windowEvents.length;
    assert.equal(harness.keydown('f'), true);
    assert.equal(harness.windowEvents.length, beforeShortcut + 1);
    assert.equal(harness.windowEvents.at(-1).detail.enabled, false);

    const beforeInteractiveShortcut = harness.windowEvents.length;
    assert.equal(harness.keydown('f', harness.interactiveTarget), false);
    assert.equal(harness.windowEvents.length, beforeInteractiveShortcut);
});

test('Desktop without native fullscreen capability fails closed without DOM fallback', async () => {
    const harness = createHarness({capabilities: ['host.status', 'host.authorize']});
    await harness.click();
    assert.equal(harness.browserEnterCount(), 0);
    assert.equal(harness.fullscreenButton.dataset.error, 'unavailable');
    assert.equal(harness.fullscreenButton.attributes.get('aria-label'), 'Tela cheia indisponível no Desktop');
});
