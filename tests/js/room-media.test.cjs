'use strict';
const assert = require('node:assert/strict');
const media = require('../../public/assets/js/room-media.js');

assert.equal(media.behindLiveMs(5954106, 5900000), 54106);
assert.equal(media.behindLiveMs(null, 5900000), null);
assert.equal(media.livePositionLabel(5954106, 5900000), '-00:54');
assert.equal(media.livePositionLabel(null, 5900000), '—');
assert.deepEqual(media.playbackPresentation({mediaMode: 'live', atLiveEdge: true,
    positionMs: 8092855, durationMs: 8065451, liveEdgeMs: 8092855}),
    {kind: 'live-edge', current: '🔴 AO VIVO', duration: ''});
assert.deepEqual(media.playbackPresentation({mediaMode: 'live', atLiveEdge: true,
    positionMs: 5954106, durationMs: 9550100, liveEdgeMs: 5954106}),
    {kind: 'live-edge', current: '🔴 AO VIVO', duration: ''});
assert.deepEqual(media.playbackPresentation({mediaMode: 'live', atLiveEdge: false,
    positionMs: 5900000, durationMs: 9550100, liveEdgeMs: 5954106}),
    {kind: 'live-dvr', current: '-00:54', duration: ''});
assert.deepEqual(media.playbackPresentation({mediaMode: 'vod', atLiveEdge: false,
    positionMs: 5238000, durationMs: 5270000, liveEdgeMs: null}),
    {kind: 'vod', current: '1:27:18', duration: '1:27:50'});
console.log('room-media tests passed');
