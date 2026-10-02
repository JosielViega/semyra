'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
    path.join(__dirname, '../../public/assets/js/room-player.js'),
    'utf8',
);
const listeners = new Map();
const mount = {
    hidden: false,
    children: [],
    append(child) { this.children.push(child); },
    replaceChildren() { this.children = []; },
};
const status = {hidden: false, textContent: '', classList: {toggle() {}}};
const document = {
    querySelector(selector) {
        if (selector === '[data-youtube-player-mount]') return mount;
        return null;
    },
    getElementById(id) { return id === 'youtube-player-status' ? status : null; },
    createElement() { return {id: ''}; },
    addEventListener(type, listener) { listeners.set(type, listener); },
    dispatchEvent() {},
    head: {append() { throw new Error('YouTube API must not load for IPTV.'); }},
};
const instances = [];
class Player {
    constructor(_target, options) {
        this.options = options;
        this.destroyCalls = 0;
        this.seekCalls = 0;
        instances.push(this);
    }
    destroy() { ++this.destroyCalls; }
    setVolume() {}
    mute() {}
    isMuted() { return true; }
    getVolume() { return 100; }
    getPlayerState() { return 1; }
    getCurrentTime() { return 0; }
    getDuration() { return 60; }
    seekTo() { ++this.seekCalls; }
    playVideo() {}
    pauseVideo() {}
}
const window = {
    location: {origin: 'https://semyra.test'},
    YT: {Player},
    SemyraMedia: {
        DEFAULT_PLAYER_VOLUME: 100,
        readStoredPlayerVolume: () => 100,
        normalizePlayerVolume: (value) => value,
        writeStoredPlayerVolume() {},
        playerVolumeSelection: () => ({volume: 100, restoreVolume: 100, muted: false}),
        playerUnmuteVolume: () => 100,
    },
    setTimeout(callback) { callback(); },
    localStorage: {},
};
class CustomEvent {
    constructor(type, options) { this.type = type; this.detail = options?.detail; }
}

vm.runInNewContext(source, {CustomEvent, Number, Promise, Set, document, window});

const update = (transmission) => listeners.get('semyra:transmission-updated')({
    detail: {transmission},
});
const flush = () => new Promise((resolve) => setImmediate(resolve));

(async () => {
    update({source: 'iptv', mediaMode: 'live', instanceId: 'a'.repeat(32), revision: 1, videoId: null});
    await flush();
    assert.equal(instances.length, 0, 'IPTV never creates or loads the YouTube player');
    assert.equal(mount.hidden, true);

    update({
        source: 'youtube', instanceId: 'a'.repeat(32), videoId: 'M7lc1UVf-VE', revision: 2,
        isOwner: false, mediaMode: 'vod',
        playback: {state: 'playing', positionMs: 0, revision: 1, atLiveEdge: false,
            liveEdgePositionMs: null, liveSyncPositionMs: null, liveSyncDelayMs: null},
    });
    await flush();
    assert.equal(instances.length, 1);
    assert.equal(mount.hidden, false);

    update({
        source: 'youtube', instanceId: 'b'.repeat(32), videoId: 'M7lc1UVf-VE', revision: 2,
        isOwner: false, mediaMode: 'vod',
        playback: {state: 'playing', positionMs: 0, revision: 1, atLiveEdge: false,
            liveEdgePositionMs: null, liveSyncPositionMs: null, liveSyncDelayMs: null},
    });
    await flush();
    assert.equal(instances.length, 2, 'same revision and video in a new instance recreates YT.Player');
    assert.equal(instances[0].destroyCalls, 1);

    instances[1].options.events.onReady({target: instances[1]});
    const appliedForB = instances[1].seekCalls;
    listeners.get('semyra:shared-playback-updated')({detail: {
        instanceId: 'a'.repeat(32), transmissionRevision: 2,
        mediaMode: 'vod', atLiveEdge: false, state: 'playing', positionMs: 2000,
        playbackRevision: 2, liveSyncPositionMs: null,
    }});
    assert.equal(instances[1].seekCalls, appliedForB,
        'a delayed playback event from instance A is not applied to instance B');

    update({
        source: 'youtube', instanceId: 'c'.repeat(32), videoId: 'dQw4w9WgXcQ', revision: 2,
        isOwner: false, mediaMode: 'vod',
        playback: {state: 'playing', positionMs: 0, revision: 1, atLiveEdge: false,
            liveEdgePositionMs: null, liveSyncPositionMs: null, liveSyncDelayMs: null},
    });
    await flush();
    assert.equal(instances.length, 3, 'same revision and another video in a new instance recreates YT.Player');
    assert.equal(instances[1].destroyCalls, 1);

    update({source: 'iptv', mediaMode: 'live', instanceId: 'd'.repeat(32), revision: 3, videoId: null});
    await flush();
    assert.equal(instances[2].destroyCalls, 1, 'source switch destroys the current YT.Player');
    assert.equal(mount.children.length, 0);
    assert.equal(mount.hidden, true);
    console.log('room-player source switch tests passed');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
