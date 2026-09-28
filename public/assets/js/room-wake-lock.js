'use strict';

((root, factory) => {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.SemyraWakeLock = api;
        api.mount(root);
    }
})(typeof window === 'undefined' ? null : window, () => {
    const shouldHoldWakeLock = ({
        supported,
        transmissionActive,
        playbackState,
        visibilityState,
    }) => supported === true
        && transmissionActive === true
        && playbackState === 'playing'
        && visibilityState === 'visible';

    const createWakeLockController = ({requestWakeLock, getVisibilityState, onState}) => {
        const supported = typeof requestWakeLock === 'function';
        let transmissionActive = false;
        let playbackState = '';
        let sentinel = null;
        let requestPending = null;
        let retryBlocked = false;
        let status = supported ? 'inactive' : 'unsupported';

        const publish = (nextStatus) => {
            status = nextStatus;
            onState?.(status);
        };

        const shouldHold = () => shouldHoldWakeLock({
            supported,
            transmissionActive,
            playbackState,
            visibilityState: getVisibilityState(),
        });

        const release = async () => {
            const held = sentinel;
            sentinel = null;
            if (held !== null && typeof held.release === 'function') {
                try {
                    await held.release();
                } catch {
                    // A sentinel can already have been released by the browser.
                }
            }
            publish(supported ? 'inactive' : 'unsupported');
        };

        const reconcile = async ({allowRetry = false} = {}) => {
            if (allowRetry) {
                retryBlocked = false;
            }
            if (!shouldHold()) {
                await release();
                return false;
            }
            if (sentinel !== null) {
                publish('active');
                return true;
            }
            if (requestPending !== null || retryBlocked) {
                return false;
            }

            publish('requesting');
            requestPending = Promise.resolve().then(() => requestWakeLock());
            try {
                const requested = await requestPending;
                if (!shouldHold()) {
                    if (typeof requested?.release === 'function') {
                        await requested.release();
                    }
                    publish('inactive');
                    return false;
                }

                sentinel = requested;
                requested?.addEventListener?.('release', () => {
                    if (sentinel !== requested) {
                        return;
                    }
                    sentinel = null;
                    retryBlocked = true;
                    publish('released');
                });
                publish('active');
                return true;
            } catch {
                retryBlocked = true;
                publish('denied');
                return false;
            } finally {
                requestPending = null;
            }
        };

        const update = ({active, state}, allowRetry = true) => {
            transmissionActive = active === true;
            playbackState = typeof state === 'string' ? state : '';
            return reconcile({allowRetry});
        };

        publish(status);
        return {
            reconcile,
            release,
            snapshot: () => ({
                playbackState,
                status,
                transmissionActive,
            }),
            update,
        };
    };

    const mount = (browserWindow) => {
        const document = browserWindow.document;
        const shell = document.querySelector('[data-room-shell]');
        if (!shell) {
            return null;
        }

        const debugEnabled = document.querySelector('[data-room-telemetry]') !== null;
        const wakeLock = browserWindow.navigator.wakeLock;
        const controller = createWakeLockController({
            requestWakeLock: typeof wakeLock?.request === 'function'
                ? () => wakeLock.request('screen')
                : null,
            getVisibilityState: () => document.visibilityState,
            onState: (state) => {
                if (debugEnabled) {
                    shell.dataset.wakeLockState = state;
                }
                document.dispatchEvent(new CustomEvent('semyra:wake-lock-state', {
                    detail: { state },
                }));
            },
        });

        const initialRevision = Number(shell.dataset.initialRevision);
        controller.update({
            active: Number.isSafeInteger(initialRevision) && initialRevision > 0,
            state: shell.dataset.initialPlaybackState ?? '',
        });

        document.addEventListener('semyra:shared-playback-updated', (event) => {
            controller.update({
                active: true,
                state: event.detail?.state ?? '',
            });
        });
        document.addEventListener('semyra:transmission-updated', (event) => {
            const transmission = event.detail?.transmission ?? null;
            controller.update({
                active: transmission !== null,
                state: transmission?.playback?.state ?? '',
            });
        });
        document.addEventListener('visibilitychange', () => {
            controller.reconcile({allowRetry: document.visibilityState === 'visible'});
        });
        document.addEventListener('fullscreenchange', () => {
            controller.reconcile({allowRetry: true});
        });
        browserWindow.addEventListener('pagehide', () => {
            controller.release();
        });

        return controller;
    };

    return {
        createWakeLockController,
        mount,
        shouldHoldWakeLock,
    };
});
