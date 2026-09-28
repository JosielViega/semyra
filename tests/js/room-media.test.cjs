'use strict';
const assert = require('node:assert/strict');
const media = require('../../public/assets/js/room-media.js');

assert.equal(media.behindLiveMs(5954106, 5900000), 54106);
assert.equal(media.behindLiveMs(null, 5900000), null);
assert.equal(media.liveSyncTargetMs(100000, 5000), 95000);
assert.equal(media.liveSyncTargetMs(3000, 5000), 0);
assert.equal(media.liveSyncTargetMs(null, 5000), null);
assert.equal(media.liveRangeMaxMs(95000), 95000);
assert.equal(media.liveRangeMaxMs(null), null);
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
assert.equal(media.localOfficialDriftMs({localState: 1, officialState: 'playing',
    localPositionMs: 100800, officialPositionMs: 100000}), 800);
assert.equal(media.localOfficialDriftMs({localState: 2, officialState: 'paused',
    localPositionMs: 98800, officialPositionMs: 100000}), -1200);
assert.equal(media.localOfficialDriftMs({localState: 3, officialState: 'playing',
    localPositionMs: 100800, officialPositionMs: 100000}), null);
const beforeAnchor = media.sharedPlaybackDispatchKey(4, 7, null);
const firstAnchor = media.sharedPlaybackDispatchKey(4, 7, 95000);
const projectedAnchor = media.sharedPlaybackDispatchKey(4, 7, 96000);
assert.notEqual(beforeAnchor, firstAnchor, 'anchorReady false -> true dispatches once');
assert.equal(firstAnchor, projectedAnchor, 'projected target changes do not dispatch another seek');
const bootstrap = {isOwner: true, mediaMode: 'live', atLiveEdge: true,
    playbackState: 'playing', liveEdgePositionMs: null, playerState: 1,
    elapsedMs: 5000, retryIntervalMs: 5000};
assert.equal(media.shouldBootstrapLiveEdge(bootstrap), true);
assert.equal(media.shouldBootstrapLiveEdge({...bootstrap, liveEdgePositionMs: 95000}), false,
    'an existing physical edge is never re-anchored from the delayed owner');
assert.equal(media.shouldBootstrapLiveEdge({...bootstrap, isOwner: false}), false,
    'a viewer never observes the physical edge');
assert.equal(media.shouldBootstrapLiveEdge({...bootstrap, atLiveEdge: false}), false,
    'DVR playback never observes the physical edge');
assert.equal(media.shouldBootstrapLiveEdge({...bootstrap, elapsedMs: 4999}), false,
    'bootstrap retries remain moderated by the existing presence polling');
console.log('room-media tests passed');
