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

        function receive(event) {
            const message = event && event.data;
            if (!message || typeof message !== 'object'
                || message.type !== PONG_TYPE
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
            if (listening) {
                webview.removeEventListener('message', receive);
                listening = false;
            }

            const detail = Object.freeze({
                protocolVersion: PROTOCOL_VERSION,
                platform: 'windows',
                appVersion: typeof message.appVersion === 'string' ? message.appVersion : '',
            });
            target.dispatchEvent(new target.CustomEvent('semyra:desktop-ready', {detail}));
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
        });
    }

    return Object.freeze({
        PING_TYPE,
        PONG_TYPE,
        PROTOCOL_VERSION,
        createDesktopBridge,
    });
}));
