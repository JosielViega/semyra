'use strict';

const assert = require('node:assert/strict');
const wakeLock = require('../../public/assets/js/room-wake-lock.js');

const createSentinel = () => {
    let releaseHandler = null;
    return {
        released: false,
        addEventListener(name, handler) {
            if (name === 'release') {
                releaseHandler = handler;
            }
        },
        async release() {
            this.released = true;
            releaseHandler?.();
        },
        simulateSystemRelease() {
            this.released = true;
            releaseHandler?.();
        },
    };
};

(async () => {
    assert.equal(wakeLock.shouldHoldWakeLock({supported: true, transmissionActive: true,
        playbackState: 'playing', visibilityState: 'visible'}), true);
    assert.equal(wakeLock.shouldHoldWakeLock({supported: false, transmissionActive: true,
        playbackState: 'playing', visibilityState: 'visible'}), false);
    assert.equal(wakeLock.shouldHoldWakeLock({supported: true, transmissionActive: true,
        playbackState: 'paused', visibilityState: 'visible'}), false);
    assert.equal(wakeLock.shouldHoldWakeLock({supported: true, transmissionActive: true,
        playbackState: 'playing', visibilityState: 'hidden'}), false);

    let visibility = 'visible';
    let requests = 0;
    const states = [];
    const firstSentinel = createSentinel();
    const controller = wakeLock.createWakeLockController({
        requestWakeLock: async () => {
            requests += 1;
            return firstSentinel;
        },
        getVisibilityState: () => visibility,
        onState: (state) => states.push(state),
    });

    assert.equal(await controller.update({active: true, state: 'playing'}), true);
    assert.equal(requests, 1);
    assert.equal(controller.snapshot().status, 'active');
    await controller.update({active: true, state: 'paused'});
    assert.equal(firstSentinel.released, true, 'pause releases the held sentinel');
    assert.equal(controller.snapshot().status, 'inactive');

    const secondSentinel = createSentinel();
    const visibilityController = wakeLock.createWakeLockController({
        requestWakeLock: async () => secondSentinel,
        getVisibilityState: () => visibility,
    });
    await visibilityController.update({active: true, state: 'playing'});
    visibility = 'hidden';
    await visibilityController.reconcile();
    assert.equal(secondSentinel.released, true, 'hidden documents release the sentinel');

    visibility = 'visible';
    let rejectedRequests = 0;
    const denied = wakeLock.createWakeLockController({
        requestWakeLock: async () => {
            rejectedRequests += 1;
            throw new Error('denied');
        },
        getVisibilityState: () => visibility,
    });
    assert.equal(await denied.update({active: true, state: 'playing'}), false);
    assert.equal(denied.snapshot().status, 'denied');
    await denied.reconcile();
    assert.equal(rejectedRequests, 1, 'a rejection does not create an immediate retry loop');
    await denied.reconcile({allowRetry: true});
    assert.equal(rejectedRequests, 2, 'a later lifecycle event may explicitly retry');

    let systemRequests = 0;
    const sentinels = [createSentinel(), createSentinel()];
    const systemRelease = wakeLock.createWakeLockController({
        requestWakeLock: async () => sentinels[systemRequests++],
        getVisibilityState: () => visibility,
    });
    await systemRelease.update({active: true, state: 'playing'});
    sentinels[0].simulateSystemRelease();
    assert.equal(systemRelease.snapshot().status, 'released');
    await systemRelease.reconcile();
    assert.equal(systemRequests, 1, 'a browser release does not retry in its own handler');
    await systemRelease.reconcile({allowRetry: true});
    assert.equal(systemRequests, 2);
    assert.equal(systemRelease.snapshot().status, 'active');

    const unsupported = wakeLock.createWakeLockController({
        requestWakeLock: null,
        getVisibilityState: () => 'visible',
    });
    assert.equal(await unsupported.update({active: true, state: 'playing'}), false);
    assert.equal(unsupported.snapshot().status, 'unsupported');
    assert.ok(states.includes('requesting') && states.includes('active'));

    console.log('room-wake-lock tests passed');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
