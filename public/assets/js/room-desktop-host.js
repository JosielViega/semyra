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
    let endCleanupContext = null;
    const safeHostSessionErrors = new Set([
        'premium_required', 'livekit_unavailable', 'host_session_expired',
        'transmission_changed', 'ingress_create_failed', 'publish_failed',
    ]);
    const schedule = typeof window.setTimeout === 'function'
        ? (callback, delay) => window.setTimeout(callback, delay)
        : () => null;
    const cancelScheduled = typeof window.clearTimeout === 'function'
        ? (timer) => window.clearTimeout(timer)
        : () => {};
    const nativeAuthorizationContext = (snapshot) => {
        const authorization = snapshot?.authorization;
        return authorization?.authorized === true
            && authorization.permission === 'media.publish'
            && typeof authorization.transmissionInstanceId === 'string'
            && /^[a-f0-9]{32}$/.test(authorization.transmissionInstanceId)
            && Number.isSafeInteger(authorization.transmissionRevision)
            && authorization.transmissionRevision > 0
            ? `${authorization.transmissionInstanceId}:${authorization.transmissionRevision}`
            : null;
    };

    const existingHost = typeof window.SemyraDesktopBridge?.getHostSnapshot === 'function'
        ? window.SemyraDesktopBridge.getHostSnapshot()
        : null;
    hostReady = existingHost?.state === 'ready'
        && Array.isArray(existingHost?.capabilities)
        && existingHost.capabilities.includes('host.authorize');

    if (hostReady) activeContext = nativeAuthorizationContext(existingHost);

    const contextKey = (value) => `${value.instanceId}:${value.revision}`;
    const contextDetail = (key) => {
        const separator = key?.lastIndexOf(':') ?? -1;
        if (separator < 1) return null;
        const transmissionInstanceId = key.slice(0, separator);
        const transmissionRevision = Number(key.slice(separator + 1));
        return /^[a-f0-9]{32}$/.test(transmissionInstanceId)
            && Number.isSafeInteger(transmissionRevision) && transmissionRevision > 0
            ? {transmissionInstanceId, transmissionRevision}
            : null;
    };
    const emitContextEvent = (type, key, detail = {}) => {
        const context = contextDetail(key);
        if (context !== null) {
            window.dispatchEvent(new CustomEvent(type, {detail: {...context, ...detail}}));
        }
    };
    const eligible = (value) => value?.source === 'iptv'
        && ['live', 'vod'].includes(value?.mediaMode)
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
        const clearedContext = contextDetail(activeContext ?? requestedContext);
        generation += 1;
        requestedContext = null;
        activeContext = null;
        if (clearedContext !== null) {
            window.dispatchEvent(new CustomEvent('semyra:host-clear-request', {detail: clearedContext}));
        }
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
        emitContextEvent('semyra:host-session-stage', key, {stage: 'host_session_requested'});
        const body = new URLSearchParams({
            _token: csrfToken,
            transmission_instance_id: transmission.instanceId,
            transmission_revision: String(transmission.revision),
        });

        let terminalErrorReported = false;
        let retryable = true;
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
                || !['media.publish', 'screen.publish'].includes(payload.permission)
                || payload.transmission_instance_id !== transmission.instanceId
                || payload.transmission_revision !== transmission.revision
                || typeof payload.expires_at !== 'string') {
                if (safeHostSessionErrors.has(payload?.error)) {
                    emitContextEvent('semyra:host-session-error', key, {errorCode: payload.error});
                    terminalErrorReported = true;
                }
                retryable = false;
                throw new Error('Invalid Host Session response.');
            }

            activeContext = key;
            requestedContext = null;
            retryCount = 0;
            retryContext = null;
            emitContextEvent('semyra:host-session-stage', key, {stage: 'host_session_received'});
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
            emitContextEvent('semyra:host-session-stage', key, {stage: 'desktop_authorization_requested'});
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
                if (!retryable) {
                    retryAfter = 0;
                    retryCount = 0;
                    retryContext = null;
                    if (!terminalErrorReported) {
                        emitContextEvent('semyra:host-session-error', key, {errorCode: 'host_session_invalid_response'});
                    }
                    return;
                }
                retryCount += 1;
                retryContext = key;
                const delay = [5000, 15000, 30000][Math.min(retryCount - 1, 2)];
                retryAfter = Date.now() + delay;
                if (retryCount <= 3) {
                    emitContextEvent('semyra:host-session-retry', key, {
                        errorCode: 'host_session_request_failed',
                        retryAttempt: retryCount,
                        retryDelayMs: delay,
                    });
                    renewalTimer = schedule(() => { void apply(); }, delay);
                } else {
                    retryAfter = 0;
                    retryCount = 0;
                    retryContext = null;
                    emitContextEvent('semyra:host-session-error', key, {errorCode: 'host_session_request_failed'});
                }
            }
        }
    };

    window.addEventListener('semyra:host-ready', (event) => {
        hostReady = event.detail?.state === 'ready'
            && Array.isArray(event.detail?.capabilities)
            && event.detail.capabilities.includes('host.authorize');
        if (hostReady && requestedContext === null && activeContext === null) {
            activeContext = nativeAuthorizationContext(event.detail);
        }
        void apply();
    });

    document.addEventListener('semyra:transmission-updated', (event) => {
        transmission = event.detail?.transmission ?? null;
        void apply();
    });
    document.addEventListener('semyra:transmission-ending', (event) => {
        if (!eligible(transmission)
            || event.detail?.instanceId !== transmission.instanceId
            || event.detail?.revision !== transmission.revision) return;
        endCleanupContext = contextKey(transmission);
        transmission = null;
        void apply();
    });
    window.addEventListener('semyra:host-cleared', (event) => {
        if (event.detail?.cleared !== true || endCleanupContext === null) return;
        const clearedContext = `${event.detail?.transmissionInstanceId}:${event.detail?.transmissionRevision}`;
        if (clearedContext === endCleanupContext) endCleanupContext = null;
    });
    window.addEventListener('pagehide', () => {
        if (eligible(transmission) || endCleanupContext !== null) {
            window.dispatchEvent(new CustomEvent('semyra:host-leave-request'));
        }
    });
})();
