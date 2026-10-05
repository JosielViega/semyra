(function (root, factory) {
    'use strict';

    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }

    api.createDesktopBridge(root).start();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const PING_TYPE = 'semyra.desktop.ping';
    const PONG_TYPE = 'semyra.desktop.pong';
    const HOST_STATUS_TYPE = 'semyra.desktop.host.status';
    const HOST_STATUS_RESULT_TYPE = 'semyra.desktop.host.status-result';
    const PROTOCOL_VERSION = 1;
    const REMEMBER_PREFERENCE_KEY = 'semyra.desktop.remember';
    const SESSION_MARKER_KEY = 'semyra.desktop.session';

    function createRequestId(target) {
        const cryptoApi = target && target.crypto;
        if (cryptoApi && typeof cryptoApi.randomUUID === 'function') {
            return cryptoApi.randomUUID();
        }

        return 'desktop-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function createDesktopBridge(target) {
        let ready = false;
        let requestId = null;
        let hostRequestId = null;
        let hostSnapshot = null;
        let webview = null;
        let listening = false;
        let rememberControl = null;
        let rememberInteracted = false;

        function storage(targetStorage) {
            return targetStorage
                && typeof targetStorage.getItem === 'function'
                && typeof targetStorage.setItem === 'function'
                ? targetStorage
                : null;
        }

        function recordRememberPreference() {
            if (!ready || !rememberControl) {
                return;
            }

            try {
                const local = storage(target.localStorage);
                const session = storage(target.sessionStorage);
                if (local && session) {
                    local.setItem(REMEMBER_PREFERENCE_KEY, rememberControl.checked ? '1' : '0');
                    session.setItem(SESSION_MARKER_KEY, '1');
                }
            } catch (error) {
                // Storage restrictions must not prevent login.
            }
        }

        function enforceRememberPreference() {
            try {
                const local = storage(target.localStorage);
                const session = storage(target.sessionStorage);
                if (!local || !session
                    || local.getItem(REMEMBER_PREFERENCE_KEY) !== '0'
                    || session.getItem(SESSION_MARKER_KEY) === '1') {
                    return;
                }

                session.setItem(SESSION_MARKER_KEY, '1');
                const document = target && target.document;
                const logoutForm = document && typeof document.querySelector === 'function'
                    ? document.querySelector('form.account-logout[action="/logout"]')
                    : null;
                if (logoutForm && typeof logoutForm.requestSubmit === 'function') {
                    logoutForm.requestSubmit();
                }
            } catch (error) {
                // Storage restrictions must not break the Desktop shell.
            }
        }

        function bindRememberPreference() {
            const document = target && target.document;
            rememberControl = document && typeof document.getElementById === 'function'
                ? document.getElementById('login-remember')
                : null;
            if (!rememberControl || typeof rememberControl.addEventListener !== 'function') {
                rememberControl = null;
                return;
            }

            rememberControl.addEventListener('change', function () {
                rememberInteracted = true;
            });
            const form = rememberControl.form;
            if (form && typeof form.addEventListener === 'function') {
                form.addEventListener('submit', recordRememberPreference);
            }
        }

        function stopListening() {
            if (listening) {
                webview.removeEventListener('message', receive);
                listening = false;
            }
        }

        function receivePong(message) {
            if (ready
                || message.requestId !== requestId
                || message.protocolVersion !== PROTOCOL_VERSION
                || message.desktop !== true
                || message.platform !== 'windows') {
                return;
            }

            ready = true;
            if (rememberControl && !rememberInteracted) {
                rememberControl.checked = true;
            }
            enforceRememberPreference();

            const detail = Object.freeze({
                protocolVersion: PROTOCOL_VERSION,
                platform: 'windows',
                appVersion: typeof message.appVersion === 'string' ? message.appVersion : '',
            });
            target.dispatchEvent(new target.CustomEvent('semyra:desktop-ready', {detail}));

            hostRequestId = createRequestId(target);
            webview.postMessage({type: HOST_STATUS_TYPE, requestId: hostRequestId});
        }

        function receiveHostStatus(message) {
            const host = message.host;
            if (!ready
                || hostSnapshot !== null
                || message.requestId !== hostRequestId
                || message.protocolVersion !== PROTOCOL_VERSION
                || !host
                || typeof host !== 'object'
                || host.state !== 'ready'
                || !Array.isArray(host.capabilities)
                || host.capabilities.length !== 1
                || host.capabilities[0] !== 'host.status') {
                return;
            }

            hostSnapshot = Object.freeze({
                state: host.state,
                capabilities: Object.freeze(host.capabilities.slice()),
            });
            stopListening();
            target.dispatchEvent(new target.CustomEvent('semyra:host-ready', {
                detail: hostSnapshot,
            }));
        }

        function receive(event) {
            const message = event && event.data;
            if (!message || typeof message !== 'object') {
                return;
            }

            switch (message.type) {
                case PONG_TYPE:
                    receivePong(message);
                    break;
                case HOST_STATUS_RESULT_TYPE:
                    receiveHostStatus(message);
                    break;
                default:
                    break;
            }
        }

        function start() {
            bindRememberPreference();
            webview = target && target.chrome && target.chrome.webview;
            if (!webview
                || typeof webview.postMessage !== 'function'
                || typeof webview.addEventListener !== 'function'
                || typeof webview.removeEventListener !== 'function') {
                return false;
            }

            requestId = createRequestId(target);
            webview.addEventListener('message', receive);
            listening = true;
            webview.postMessage({type: PING_TYPE, requestId});
            return true;
        }

        return Object.freeze({
            start,
            isReady: function () {
                return ready;
            },
            getHostSnapshot: function () {
                return hostSnapshot;
            },
        });
    }

    return Object.freeze({
        PING_TYPE,
        PONG_TYPE,
        HOST_STATUS_TYPE,
        HOST_STATUS_RESULT_TYPE,
        PROTOCOL_VERSION,
        createDesktopBridge,
    });
}));
