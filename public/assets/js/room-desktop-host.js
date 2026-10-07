'use strict';

(() => {
    const shell = document.querySelector('[data-room-shell]');
    if (!shell) {
        return;
    }

    const endpoint = shell.dataset.desktopHostSessionUrl ?? '';
    const csrfToken = shell.dataset.csrfToken ?? '';
    const roomCodeMatch = /^\/room\/([A-Z0-9]{8})$/.exec(window.location.pathname);
    if (!endpoint || !csrfToken || !roomCodeMatch) {
        return;
    }

    const roomCode = roomCodeMatch[1];
    let hostReady = false;
    let transmission = null;
    let requestedContext = null;
    let activeContext = null;
    let generation = 0;
    let retryAfter = 0;
    let renewalTimer = null;
    let retryCount = 0;
    let retryContext = null;
    const schedule = typeof window.setTimeout === 'function'
        ? (callback, delay) => window.setTimeout(callback, delay)
        : () => null;
    const cancelScheduled = typeof window.clearTimeout === 'function'
        ? (timer) => window.clearTimeout(timer)
        : () => {};

    const existingHost = typeof window.SemyraDesktopBridge?.getHostSnapshot === 'function'
        ? window.SemyraDesktopBridge.getHostSnapshot()
        : null;
    hostReady = existingHost?.state === 'ready'
        && Array.isArray(existingHost?.capabilities)
        && existingHost.capabilities.includes('host.authorize');

    const contextKey = (value) => `${value.instanceId}:${value.revision}`;
    const eligible = (value) => value?.source === 'iptv'
        && value?.mediaMode === 'live'
        && value?.isOwner === true
        && typeof value?.instanceId === 'string'
        && /^[a-f0-9]{32}$/.test(value.instanceId)
        && Number.isSafeInteger(value?.revision)
        && value.revision > 0;

    const clearAuthorization = () => {
        if (renewalTimer !== null) {
            cancelScheduled(renewalTimer);
            renewalTimer = null;
        }
        retryAfter = 0;
        retryCount = 0;
        retryContext = null;
        if (requestedContext === null && activeContext === null) {
            return;
        }
        generation += 1;
        requestedContext = null;
        activeContext = null;
        window.dispatchEvent(new CustomEvent('semyra:host-clear-request'));
    };

    const apply = async () => {
        if (!hostReady || !eligible(transmission)) {
            clearAuthorization();
            return;
        }

        const key = contextKey(transmission);
        if (key === requestedContext || key === activeContext || (key === retryContext && Date.now() < retryAfter)) {
            return;
        }
        if (key !== retryContext) {
            if (renewalTimer !== null) {
                cancelScheduled(renewalTimer);
                renewalTimer = null;
            }
            retryAfter = 0;
            retryCount = 0;
            retryContext = null;
        }
        if (requestedContext !== null || activeContext !== null) {
            clearAuthorization();
        }

        const requestGeneration = ++generation;
        requestedContext = key;
        const body = new URLSearchParams({
            _token: csrfToken,
            transmission_instance_id: transmission.instanceId,
            transmission_revision: String(transmission.revision),
        });

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body,
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (requestGeneration !== generation || requestedContext !== key) {
                return;
            }
            if (!response.ok
                || typeof payload?.host_session_token !== 'string'
                || !/^[a-f0-9]{32}\.[a-f0-9]{64}$/.test(payload.host_session_token)
                || payload.permission !== 'media.publish'
                || payload.transmission_instance_id !== transmission.instanceId
                || payload.transmission_revision !== transmission.revision
                || typeof payload.expires_at !== 'string') {
                throw new Error('Invalid Host Session response.');
            }

            activeContext = key;
            requestedContext = null;
            retryCount = 0;
            retryContext = null;
            window.dispatchEvent(new CustomEvent('semyra:host-authorization-request', {
                detail: {
                    hostSessionToken: payload.host_session_token,
                    roomCode,
                    transmissionInstanceId: payload.transmission_instance_id,
                    transmissionRevision: payload.transmission_revision,
                    permission: payload.permission,
                    expiresAt: payload.expires_at,
                },
            }));
            const renewIn = Math.max(1000, Date.parse(payload.expires_at) - Date.now() - 60000);
            renewalTimer = schedule(() => {
                if (activeContext === key) {
                    activeContext = null;
                    void apply();
                }
            }, renewIn);
        } catch {
            if (requestGeneration === generation) {
                requestedContext = null;
                retryCount += 1;
                retryContext = key;
                const delay = [5000, 15000, 30000][Math.min(retryCount - 1, 2)];
                retryAfter = Date.now() + delay;
                if (retryCount <= 3) {
                    renewalTimer = schedule(() => { void apply(); }, delay);
                }
            }
        }
    };

    window.addEventListener('semyra:host-ready', (event) => {
        hostReady = event.detail?.state === 'ready'
            && Array.isArray(event.detail?.capabilities)
            && event.detail.capabilities.includes('host.authorize');
        void apply();
    });

    document.addEventListener('semyra:presence-updated', (event) => {
        transmission = event.detail?.transmission ?? null;
        void apply();
    });
    window.addEventListener('semyra:transmission-updated', (event) => {
        transmission = event.detail ?? null;
        void apply();
    });
})();
