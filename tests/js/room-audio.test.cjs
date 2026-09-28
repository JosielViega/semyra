'use strict';

const assert = require('node:assert/strict');
const media = require('../../public/assets/js/room-media.js');

assert.equal(media.PLAYER_VOLUME_STORAGE_KEY, 'semyra:player-volume');
assert.equal(media.normalizePlayerVolume(-20), 0);
assert.equal(media.normalizePlayerVolume(40.6), 41);
assert.equal(media.normalizePlayerVolume(140), 100);
assert.equal(media.normalizePlayerVolume('72'), 72);
assert.equal(media.normalizePlayerVolume('invalid'), 100);
assert.equal(media.normalizePlayerVolume('', 35), 35);

assert.equal(media.readStoredPlayerVolume({getItem: () => '35'}), 35);
assert.equal(media.readStoredPlayerVolume({getItem: () => 'invalid'}), 100);
assert.equal(media.readStoredPlayerVolume({getItem: () => { throw new Error('denied'); }}), 100);

let stored = null;
assert.equal(media.writeStoredPlayerVolume({setItem: (key, value) => { stored = {key, value}; }}, 67), 67);
assert.deepEqual(stored, {key: 'semyra:player-volume', value: '67'});
assert.doesNotThrow(() => media.writeStoredPlayerVolume({
    setItem: () => { throw new Error('quota'); },
}, 55));

assert.deepEqual(media.playerVolumeSelection(0, 64), {
    muted: true,
    volume: 0,
    restoreVolume: 64,
});
assert.deepEqual(media.playerVolumeSelection(38, 64), {
    muted: false,
    volume: 38,
    restoreVolume: 38,
});
assert.equal(media.playerUnmuteVolume(38, 64), 38);
assert.equal(media.playerUnmuteVolume(0, 64), 64, 'mute toggle restores the last non-zero level');
assert.equal(media.playerUnmuteVolume(0, 0), 100);

const mutedState = media.playerVolumeSelection(0, 74);
const audibleState = media.playerVolumeSelection(74, mutedState.restoreVolume);
assert.deepEqual(
    {muted: mutedState.muted, volume: mutedState.volume},
    {muted: true, volume: 0},
);
assert.deepEqual(
    {muted: audibleState.muted, volume: audibleState.volume},
    {muted: false, volume: 74},
);

console.log('room-audio tests passed');
