'use strict';

const assert = require('node:assert/strict');
const media = require('../../public/assets/js/room-media.js');

const vod = media.createScrubbingSession();
assert.equal(vod.start(30000, true), true);
assert.equal(vod.isActive(), true);
assert.equal(vod.update(120000), true);
assert.equal(vod.positionMs(), 120000, 'polling must leave the local thumb position intact');
assert.equal(media.formatTime(vod.positionMs()), '02:00', 'input updates the VOD preview');
assert.deepEqual(vod.commit('vod', null), {action: 'seek', positionMs: 120000});
assert.equal(vod.commit('vod', null), null, 'one interaction emits only one command');

const liveDvr = media.createScrubbingSession();
assert.equal(liveDvr.start(600000, true), true);
assert.equal(liveDvr.update(510000), true);
assert.equal(liveDvr.positionMs(), 510000, 'polling cannot force the thumb back to the edge');
assert.equal(media.livePositionLabel(600000, liveDvr.positionMs()), '-01:30');
assert.deepEqual(liveDvr.commit('live', 600000), {action: 'seek', positionMs: 510000});

const nearLive = media.createScrubbingSession();
nearLive.start(590000, true);
nearLive.update(596000);
assert.equal(media.livePositionLabel(600000, nearLive.positionMs()), '🔴 AO VIVO');
assert.deepEqual(nearLive.commit('live', 600000), {action: 'live', positionMs: null});

const cancelled = media.createScrubbingSession();
cancelled.start(30000, true);
cancelled.update(45000);
cancelled.cancel();
assert.equal(cancelled.isActive(), false);
assert.equal(cancelled.commit('vod', null), null);

const viewer = media.createScrubbingSession();
assert.equal(viewer.start(30000, false), false);
assert.equal(viewer.update(45000), false);
assert.equal(viewer.commit('vod', null), null, 'viewer cannot produce a command');

console.log('room-scrubbing tests passed');
