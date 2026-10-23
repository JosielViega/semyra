'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const sourceCode = fs.readFileSync('public/assets/js/room-desktop-iptv.js', 'utf8');

const harness = (premium) => {
    const listeners = new Map();
    const requests = [];
    const elements = new Map();
    const element = (extra = {}) => Object.assign({
        hidden: false,
        dataset: {},
        textContent: '',
        listeners: new Map(),
        addEventListener(type, listener) { this.listeners.set(type, listener); },
        querySelectorAll() { return []; },
        replaceChildren(...children) { this.children = children; },
    }, extra);
    const shell = element({dataset: {canPublishIptv: premium ? '1' : '0', csrfToken: 'csrf', iptvTransmissionUrl: '/iptv'}});
    const tabs = element();
    const youtube = element();
    const iptv = element({hidden: true});
    const controls = element();
    const unavailable = element({hidden: true});
    const status = element({textContent: premium ? '' : 'Transmitir sua IPTV para a sala é um recurso Premium.'});
    const kicker = element();
    const submit = element();
    for (const [selector, value] of [
        ['[data-room-shell]', shell], ['[data-desktop-publish-tabs]', tabs],
        ['[data-youtube-source-panel]', youtube], ['[data-iptv-source-panel]', iptv],
        ['[data-room-iptv-controls]', controls], ['[data-room-iptv-unavailable]', unavailable],
        ['[data-room-iptv-status]', status], ['[data-transmission-kicker]', kicker],
        ['.room-dialog-actions button[type="submit"]', submit],
    ]) elements.set(selector, value);
    const source = element({value: '7'});
    const search = element({value: 'news'});
    const group = element({value: 'Live'});
    const results = element();
    const find = element();
    for (const [selector, value] of [
        ['[data-room-iptv-source]', source], ['[data-room-iptv-search]', search],
        ['[data-room-iptv-group]', group], ['[data-room-iptv-results]', results],
        ['[data-room-iptv-find]', find],
    ]) elements.set(selector, value);
    class CustomEvent { constructor(type, options) { this.type = type; this.detail = options?.detail; } }
    const window = {
        addEventListener(type, listener) { listeners.set(type, listener); },
        dispatchEvent(event) { requests.push(event.detail); listeners.get(event.type)?.(event); },
    };
    const document = {
        querySelector: (selector) => elements.get(selector) || null,
        createElement: () => element(),
        dispatchEvent() {},
    };
    vm.runInNewContext(sourceCode, {window, document, CustomEvent, URLSearchParams, console});
    return {
        tabs, controls, unavailable, status, requests,
        hostReady(capabilities = ['iptv.catalog', 'livekit.publish']) {
            listeners.get('semyra:host-ready')({detail: {state: 'ready', capabilities}});
        },
        chooseIptv() { tabs.listeners.get('click')({target: {closest: () => ({dataset: {sourceTab: 'iptv'}})}}); },
        search() { find.listeners.get('click')(); },
        changeSource() { source.listeners.get('change')(); },
        chooseChannel() { results.listeners.get('click')({target: {closest: () => ({dataset: {channelId: '11'}})}}); },
        hostAuthorized() { listeners.get('semyra:host-authorized')(); },
        catalogResult() { listeners.get('semyra:iptv-result')({detail: {action: 'list', ok: true, sources: [{id: 7, name: 'Source'}]}}); },
        groupsResult() { listeners.get('semyra:iptv-result')({detail: {action: 'groups', ok: true, groups: ['Live']}}); },
        hostSessionError(errorCode) { listeners.get('semyra:host-session-error')({detail: {errorCode}}); },
    };
};

test('free account sees the Premium IPTV tab but never requests the native catalog', () => {
    const page = harness(false);
    page.hostReady();
    page.chooseIptv();
    page.search();
    page.changeSource();
    page.chooseChannel();
    page.hostAuthorized();
    page.catalogResult();

    assert.equal(page.tabs.hidden, false);
    assert.match(page.status.textContent, /recurso Premium/);
    assert.deepEqual(page.requests, []);
});

test('premium account without publish capability sees a clear unavailable state and sends no IPTV request', () => {
    const page = harness(true);
    page.hostReady(['iptv.catalog']);
    page.chooseIptv();
    page.search();
    page.changeSource();
    page.chooseChannel();
    page.hostAuthorized();

    assert.equal(page.tabs.hidden, false);
    assert.equal(page.controls.hidden, true);
    assert.equal(page.unavailable.hidden, false);
    assert.deepEqual(page.requests, []);
});

test('premium account with catalog and publish loads once and enables catalog actions', () => {
    const page = harness(true);
    page.hostReady();
    assert.equal(page.requests.filter((request) => request?.action === 'list').length, 1);

    page.chooseIptv();
    page.hostReady();
    assert.equal(page.requests.filter((request) => request?.action === 'list').length, 1);
    assert.equal(page.controls.hidden, false);
    assert.equal(page.unavailable.hidden, true);

    page.catalogResult();
    assert.equal(page.requests.at(-1).action, 'groups');
    page.groupsResult();
    page.search();
    assert.equal(page.requests.at(-1).action, 'search');
});

test('sanitized publish and Host Session failures have understandable messages', () => {
    const page = harness(true);
    page.hostReady();
    page.hostSessionError('transmission_changed');
    assert.equal(page.status.textContent, 'A transmissão da sala mudou. Tente novamente.');
    page.hostSessionError('livekit_unavailable');
    assert.equal(page.status.textContent, 'O serviço de transmissão está temporariamente indisponível.');
});
