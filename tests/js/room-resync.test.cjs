'use strict';

const assert = require('node:assert/strict');
const media = require('../../public/assets/js/room-media.js');

assert.equal(media.SYNC_SETTLE_DELAY_MS, 2000);
assert.equal(media.PARTICIPANT_SYNC_WARMUP_MS, 4000);
assert.equal(media.AUTO_RESYNC_RETRY_DELAY_MS, 5000);
assert.deepEqual(media.resyncPlan({mediaMode: 'vod', state: 'playing', atLiveEdge: false}),
    {firstAction: 'pause', resumeAction: 'play'});
assert.deepEqual(media.resyncPlan({mediaMode: 'vod', state: 'paused', atLiveEdge: false}),
    {firstAction: 'seek', resumeAction: null});
assert.deepEqual(media.resyncPlan({mediaMode: 'live', state: 'playing', atLiveEdge: true}),
    {firstAction: 'pause', resumeAction: 'live'});
assert.deepEqual(media.resyncPlan({mediaMode: 'live', state: 'playing', atLiveEdge: false}),
    {firstAction: 'pause', resumeAction: 'play'});
assert.deepEqual(media.resyncPlan({mediaMode: 'live', state: 'paused', atLiveEdge: false}),
    {firstAction: 'seek', resumeAction: null});

const simulate = async ({mediaMode, state, atLiveEdge, mutateDuringWait = null}) => {
    const commands = [];
    const waits = [];
    let context = {isOwner: true, transmissionRevision: 4, playbackRevision: 7,
        playbackPositionMs: 123456};
    const result = await media.runResyncSequence({
        transmission: {mediaMode, state, atLiveEdge},
        requestSnapshot: async () => ({positionMs: 123456}),
        sendCommand: async (action, positionMs, expected) => {
            assert.deepEqual(expected, context);
            commands.push({action, positionMs});
            context = {...context, playbackRevision: context.playbackRevision + 1,
                playbackPositionMs: positionMs ?? context.playbackPositionMs};
            return {ok: true};
        },
        wait: async (milliseconds) => {
            waits.push(milliseconds);
            mutateDuringWait?.(context, (next) => { context = next; });
        },
        currentContext: () => context,
    });
    return {commands, waits, result};
};

(async () => {
    const vodPlaying = await simulate({mediaMode: 'vod', state: 'playing', atLiveEdge: false});
    assert.deepEqual(vodPlaying.commands, [
        {action: 'pause', positionMs: 123456},
        {action: 'play', positionMs: 123456},
    ]);
    assert.deepEqual(vodPlaying.waits, [2000]);

    const vodPaused = await simulate({mediaMode: 'vod', state: 'paused', atLiveEdge: false});
    assert.deepEqual(vodPaused.commands, [{action: 'seek', positionMs: 123456}]);
    assert.deepEqual(vodPaused.waits, []);

    const liveEdge = await simulate({mediaMode: 'live', state: 'playing', atLiveEdge: true});
    assert.deepEqual(liveEdge.commands.map(({action}) => action), ['pause', 'live']);

    const liveDvr = await simulate({mediaMode: 'live', state: 'playing', atLiveEdge: false});
    assert.deepEqual(liveDvr.commands.map(({action}) => action), ['pause', 'play']);

    const liveDvrPaused = await simulate({mediaMode: 'live', state: 'paused', atLiveEdge: false});
    assert.deepEqual(liveDvrPaused.commands.map(({action}) => action), ['seek']);

    const replaced = await simulate({
        mediaMode: 'vod', state: 'playing', atLiveEdge: false,
        mutateDuringWait: (context, update) => update({...context, transmissionRevision: 5}),
    });
    assert.deepEqual(replaced.commands.map(({action}) => action), ['pause']);
    assert.equal(replaced.result.reason, 'stale');

    const ownerLost = await simulate({
        mediaMode: 'live', state: 'playing', atLiveEdge: true,
        mutateDuringWait: (context, update) => update({...context, isOwner: false}),
    });
    assert.deepEqual(ownerLost.commands.map(({action}) => action), ['pause']);
    assert.equal(ownerLost.result.reason, 'stale');

    const lock = media.createResyncLock();
    assert.equal(lock.begin(), true);
    assert.equal(lock.begin(), false, 'double click cannot start a second resync');
    lock.end();
    assert.equal(lock.begin(), true);

    const ids = {
        a: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        b: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        c: 'cccccccccccccccccccccccccccccccc',
        d: 'dddddddddddddddddddddddddddddddd',
    };
    const participant = (id, fresh = true, name = 'Pedro') => ({
        public_id: id,
        name,
        playback: fresh ? {fresh: true} : {fresh: false},
    });
    const ready = (...selected) => selected.map((id) => participant(id));

    const initial = media.createParticipantSyncTracker();
    assert.equal(initial.observe({participants: ready(ids.a, ids.b), now: 0,
        officialState: 'playing'}).shouldResync, false);
    assert.equal(initial.observe({participants: ready(ids.a, ids.b), now: 3999,
        officialState: 'playing'}).shouldResync, false);
    assert.equal(initial.observe({participants: ready(ids.a, ids.b), now: 4000,
        officialState: 'playing'}).shouldResync, true);
    initial.markSynchronized(initial.readyParticipantIds());
    assert.deepEqual(initial.synchronizedParticipantIds().sort(), [ids.a, ids.b]);
    assert.equal(initial.observe({participants: ready(ids.a, ids.b), now: 5000,
        officialState: 'playing'}).shouldResync, false, 'the next poll does not repeat');

    assert.equal(initial.observe({participants: [...ready(ids.a, ids.b), participant(ids.c, false)],
        now: 6000, officialState: 'playing'}).shouldResync, false);
    assert.equal(initial.observe({participants: ready(ids.a, ids.b, ids.c), now: 7000,
        officialState: 'playing'}).shouldResync, false);
    assert.equal(initial.observe({participants: ready(ids.a, ids.b, ids.c), now: 10999,
        officialState: 'playing'}).shouldResync, false);
    assert.equal(initial.observe({participants: ready(ids.a, ids.b, ids.c), now: 11000,
        officialState: 'playing'}).shouldResync, true, 'late C triggers after continuous warmup');
    initial.markSynchronized(initial.readyParticipantIds());
    assert.equal(initial.observe({participants: ready(ids.a, ids.b, ids.c), now: 12000,
        officialState: 'playing'}).shouldResync, false);
    initial.observe({participants: ready(ids.a, ids.b, ids.c, ids.d), now: 13000,
        officialState: 'playing'});
    assert.equal(initial.observe({participants: ready(ids.a, ids.b, ids.c, ids.d), now: 17000,
        officialState: 'playing'}).shouldResync, true, 'D creates a later cohort');

    const batch = media.createParticipantSyncTracker({synchronizedIds: [ids.a, ids.b]});
    batch.observe({participants: ready(ids.a, ids.b, ids.c), now: 0,
        officialState: 'playing'});
    batch.observe({participants: ready(ids.a, ids.b, ids.c, ids.d), now: 1000,
        officialState: 'playing'});
    assert.equal(batch.observe({participants: ready(ids.a, ids.b, ids.c, ids.d), now: 4000,
        officialState: 'playing'}).shouldResync, true,
    'C can trigger while ready D is batched before completing its own warmup');
    batch.markSynchronized(batch.readyParticipantIds());
    assert.deepEqual(batch.synchronizedParticipantIds().sort(), [ids.a, ids.b, ids.c, ids.d]);

    const duringBarrier = media.createParticipantSyncTracker({synchronizedIds: [ids.a, ids.b]});
    duringBarrier.observe({participants: ready(ids.a, ids.b, ids.c), now: 0,
        officialState: 'playing'});
    duringBarrier.observe({participants: ready(ids.a, ids.b, ids.c), now: 4000,
        officialState: 'playing'});
    const capturedAtBarrierStart = duringBarrier.readyParticipantIds();
    duringBarrier.observe({participants: ready(ids.a, ids.b, ids.c, ids.d), now: 5000,
        officialState: 'paused', allowPausedStabilization: false});
    duringBarrier.markSynchronized(capturedAtBarrierStart);
    assert.equal(duringBarrier.synchronizedParticipantIds().includes(ids.d), false,
        'a participant becoming ready during the barrier is not marked prematurely');

    const resetWarmup = media.createParticipantSyncTracker({synchronizedIds: [ids.a, ids.b]});
    resetWarmup.observe({participants: ready(ids.a, ids.b, ids.c), now: 0,
        officialState: 'playing'});
    resetWarmup.observe({participants: [...ready(ids.a, ids.b), participant(ids.c, false)], now: 3000,
        officialState: 'playing'});
    resetWarmup.observe({participants: ready(ids.a, ids.b, ids.c), now: 4000,
        officialState: 'playing'});
    assert.equal(resetWarmup.observe({participants: ready(ids.a, ids.b, ids.c), now: 7999,
        officialState: 'playing'}).shouldResync, false);
    assert.equal(resetWarmup.observe({participants: ready(ids.a, ids.b, ids.c), now: 8000,
        officialState: 'playing'}).shouldResync, true);

    const rejoin = media.createParticipantSyncTracker({synchronizedIds: [ids.a, ids.b, ids.c]});
    assert.equal(rejoin.observe({participants: ready(ids.a, ids.b), now: 0,
        officialState: 'playing'}).changed, true);
    rejoin.observe({participants: ready(ids.a, ids.b, ids.c), now: 100,
        officialState: 'playing'});
    assert.equal(rejoin.observe({participants: ready(ids.a, ids.b, ids.c), now: 4100,
        officialState: 'playing'}).shouldResync, true, 'a real rejoin receives a new warmup');

    const paused = media.createParticipantSyncTracker({synchronizedIds: [ids.a, ids.b]});
    paused.observe({participants: ready(ids.a, ids.b, ids.c), now: 0,
        officialState: 'paused'});
    const pausedStable = paused.observe({participants: ready(ids.a, ids.b, ids.c), now: 4000,
        officialState: 'paused'});
    assert.equal(pausedStable.shouldResync, false);
    assert.equal(pausedStable.changed, true);
    assert.ok(paused.synchronizedParticipantIds().includes(ids.c));
    assert.equal(paused.observe({participants: ready(ids.a, ids.b, ids.c), now: 5000,
        officialState: 'playing'}).shouldResync, false);

    const manual = media.createParticipantSyncTracker({synchronizedIds: [ids.a, ids.b]});
    manual.observe({participants: ready(ids.a, ids.b, ids.c), now: 0,
        officialState: 'playing'});
    manual.markSynchronized(manual.readyParticipantIds());
    assert.equal(manual.observe({participants: ready(ids.a, ids.b, ids.c), now: 5000,
        officialState: 'playing'}).shouldResync, false, 'manual sync prevents redundant auto-resync');

    const retry = media.createParticipantSyncTracker();
    retry.observe({participants: ready(ids.a, ids.b), now: 0, officialState: 'playing'});
    assert.equal(retry.observe({participants: ready(ids.a, ids.b), now: 4000,
        officialState: 'playing'}).shouldResync, true);
    retry.deferRetry(4000);
    assert.equal(retry.observe({participants: ready(ids.a, ids.b), now: 8999,
        officialState: 'playing'}).shouldResync, false);
    assert.equal(retry.observe({participants: ready(ids.a, ids.b), now: 9000,
        officialState: 'playing'}).shouldResync, true);

    assert.notEqual(media.participantSyncStorageKey('/room/A/p', 4),
        media.participantSyncStorageKey('/room/A/p', 5));

    console.log('room-resync tests passed');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
