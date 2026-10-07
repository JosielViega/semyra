(function (root, factory) {
    'use strict';

    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }

    const bridge = api.createDesktopBridge(root);
    root.SemyraDesktopBridge = bridge;
    bridge.start();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const PING_TYPE = 'semyra.desktop.ping';
    const PONG_TYPE = 'semyra.desktop.pong';
    const HOST_STATUS_TYPE = 'semyra.desktop.host.status';
    const HOST_STATUS_RESULT_TYPE = 'semyra.desktop.host.status-result';
    const HOST_AUTHORIZE_TYPE = 'semyra.desktop.host.authorize';
    const HOST_AUTHORIZE_RESULT_TYPE = 'semyra.desktop.host.authorize-result';
    const HOST_CLEAR_TYPE = 'semyra.desktop.host.clear';
    const HOST_CLEAR_RESULT_TYPE = 'semyra.desktop.host.clear-result';
    const IPTV_COMMANDS = Object.freeze({
        list: ['semyra.desktop.iptv.sources.list', 'semyra.desktop.iptv.sources.list-result'],
        'add-url': ['semyra.desktop.iptv.sources.add', 'semyra.desktop.iptv.sources.add-result'],
        remove: ['semyra.desktop.iptv.sources.remove', 'semyra.desktop.iptv.sources.remove-result'],
        refresh: ['semyra.desktop.iptv.sources.refresh', 'semyra.desktop.iptv.sources.refresh-result'],
        'pick-file': ['semyra.desktop.iptv.sources.pick-file', 'semyra.desktop.iptv.sources.pick-file-result'],
        groups: ['semyra.desktop.iptv.groups.list', 'semyra.desktop.iptv.groups.list-result'],
        search: ['semyra.desktop.iptv.channels.search', 'semyra.desktop.iptv.channels.search-result'],
        'media-start': ['semyra.desktop.iptv.media.start', 'semyra.desktop.iptv.media.start-result'],
        'media-stop': ['semyra.desktop.iptv.media.stop', 'semyra.desktop.iptv.media.stop-result'],
        'media-status': ['semyra.desktop.iptv.media.status', 'semyra.desktop.iptv.media.status-result'],
    });
    const IPTV_MEDIA_STATE_TYPE = 'semyra.desktop.iptv.media.state';
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
        let authorizationRequestId = null;
        let authorizationContext = null;
        let clearRequestId = null;
        let webview = null;
        let listening = false;
        let rememberControl = null;
        let rememberInteracted = false;
        const iptvRequests = new Map();

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
                || ![4, 5].includes(host.capabilities.length)
                || host.capabilities[0] !== 'host.status'
                || host.capabilities[1] !== 'host.authorize'
                || host.capabilities[2] !== 'iptv.sources'
                || host.capabilities[3] !== 'iptv.catalog'
                || (host.capabilities.length === 5 && host.capabilities[4] !== 'iptv.play')
                || !host.authorization
                || typeof host.authorization !== 'object'
                || typeof host.authorization.authorized !== 'boolean') {
                return;
            }

            hostSnapshot = Object.freeze({
                state: host.state,
                capabilities: Object.freeze(host.capabilities.slice()),
                authorization: safeAuthorization(host.authorization),
            });
            const document = target && target.document;
            if (document && typeof document.querySelectorAll === 'function') {
                document.querySelectorAll('[data-desktop-iptv-link]').forEach(function (link) {
                    link.hidden = false;
                });
            }
            target.dispatchEvent(new target.CustomEvent('semyra:host-ready', {
                detail: hostSnapshot,
            }));
        }

        function safeAuthorization(authorization) {
            const authorized = authorization?.authorized === true;
            return Object.freeze({
                authorized,
                permission: authorized && authorization.permission === 'media.publish'
                    ? authorization.permission : null,
                transmissionInstanceId: authorized
                    && typeof authorization.transmissionInstanceId === 'string'
                    ? authorization.transmissionInstanceId : null,
                transmissionRevision: authorized
                    && Number.isSafeInteger(authorization.transmissionRevision)
                    ? authorization.transmissionRevision : null,
            });
        }

        function authorizationDetail(detail) {
            if (!detail || typeof detail !== 'object'
                || typeof detail.hostSessionToken !== 'string'
                || !/^[a-f0-9]{32}\.[a-f0-9]{64}$/.test(detail.hostSessionToken)
                || typeof detail.roomCode !== 'string'
                || !/^[A-Z0-9]{8}$/.test(detail.roomCode)
                || typeof detail.transmissionInstanceId !== 'string'
                || !/^[a-f0-9]{32}$/.test(detail.transmissionInstanceId)
                || !Number.isSafeInteger(detail.transmissionRevision)
                || detail.transmissionRevision < 1
                || detail.permission !== 'media.publish'
                || typeof detail.expiresAt !== 'string'
                || !Number.isFinite(Date.parse(detail.expiresAt))
                || Date.parse(detail.expiresAt) <= Date.now()) {
                return null;
            }

            return detail;
        }

        function requestAuthorization(event) {
            if (!hostSnapshot || !hostSnapshot.capabilities.includes('host.authorize')) {
                return;
            }
            const detail = authorizationDetail(event?.detail);
            if (detail === null) {
                return;
            }

            authorizationRequestId = createRequestId(target);
            authorizationContext = Object.freeze({
                permission: detail.permission,
                transmissionInstanceId: detail.transmissionInstanceId,
                transmissionRevision: detail.transmissionRevision,
            });
            webview.postMessage({
                type: HOST_AUTHORIZE_TYPE,
                requestId: authorizationRequestId,
                hostSessionToken: detail.hostSessionToken,
                roomCode: detail.roomCode,
                transmissionInstanceId: detail.transmissionInstanceId,
                transmissionRevision: detail.transmissionRevision,
                permission: detail.permission,
                expiresAt: detail.expiresAt,
            });
        }

        function requestClear() {
            if (!hostSnapshot) {
                return;
            }
            authorizationRequestId = null;
            authorizationContext = null;
            clearRequestId = createRequestId(target);
            webview.postMessage({type: HOST_CLEAR_TYPE, requestId: clearRequestId});
        }

        function receiveHostAuthorize(message) {
            if (message.requestId !== authorizationRequestId
                || message.protocolVersion !== PROTOCOL_VERSION
                || message.authorized !== true
                || !authorizationContext
                || message.permission !== authorizationContext.permission
                || message.transmissionInstanceId !== authorizationContext.transmissionInstanceId
                || message.transmissionRevision !== authorizationContext.transmissionRevision) {
                return;
            }

            const detail = Object.freeze({
                authorized: true,
                permission: message.permission,
                transmissionInstanceId: message.transmissionInstanceId,
                transmissionRevision: message.transmissionRevision,
            });
            hostSnapshot = Object.freeze({
                state: hostSnapshot.state,
                capabilities: hostSnapshot.capabilities,
                authorization: detail,
            });
            authorizationRequestId = null;
            authorizationContext = null;
            target.dispatchEvent(new target.CustomEvent('semyra:host-authorized', {detail}));
        }

        function receiveHostClear(message) {
            if (message.requestId !== clearRequestId
                || message.protocolVersion !== PROTOCOL_VERSION
                || message.cleared !== true) {
                return;
            }

            const detail = safeAuthorization({authorized: false});
            hostSnapshot = Object.freeze({
                state: hostSnapshot.state,
                capabilities: hostSnapshot.capabilities,
                authorization: detail,
            });
            clearRequestId = null;
            target.dispatchEvent(new target.CustomEvent('semyra:host-cleared', {detail}));
        }

        function positiveId(value) {
            return Number.isSafeInteger(value) && value > 0 ? value : null;
        }

        function requestIptv(event) {
            if (!hostSnapshot
                || !hostSnapshot.capabilities.includes('iptv.sources')
                || !hostSnapshot.capabilities.includes('iptv.catalog')) {
                return;
            }
            const detail = event && event.detail;
            const action = detail && typeof detail.action === 'string' ? detail.action : '';
            const command = IPTV_COMMANDS[action];
            if (!command) {
                return;
            }

            const request = {type: command[0], requestId: createRequestId(target)};
            if (action === 'add-url') {
                if (typeof detail.name !== 'string' || detail.name.length < 1 || detail.name.length > 100
                    || typeof detail.location !== 'string' || detail.location.length < 1 || detail.location.length > 4096) {
                    return;
                }
                request.name = detail.name;
                request.location = detail.location;
                request.sourceType = 'm3u_url';
            } else if (action === 'remove' || action === 'refresh' || action === 'groups') {
                request.sourceId = positiveId(detail.sourceId);
                if (request.sourceId === null) {
                    return;
                }
            } else if (action === 'search') {
                request.sourceId = positiveId(detail.sourceId);
                request.query = typeof detail.query === 'string' && detail.query.length <= 120 ? detail.query : '';
                request.group = typeof detail.group === 'string' && detail.group.length <= 240 && detail.group.length > 0
                    ? detail.group : null;
                request.offset = Number.isSafeInteger(detail.offset) && detail.offset >= 0 ? detail.offset : 0;
                request.limit = Number.isSafeInteger(detail.limit) && detail.limit >= 1 && detail.limit <= 100 ? detail.limit : 50;
                if (request.sourceId === null) {
                    return;
                }
            } else if (action === 'media-start') {
                if (!hostSnapshot.capabilities.includes('iptv.play')) {
                    return;
                }
                request.channelId = positiveId(detail.channelId);
                if (request.channelId === null) {
                    return;
                }
            }

            iptvRequests.set(request.requestId, Object.freeze({action, resultType: command[1]}));
            webview.postMessage(request);
        }

        function safeSource(source) {
            if (!source || !positiveId(source.id) || typeof source.name !== 'string'
                || !['m3u_url', 'm3u_file'].includes(source.type)
                || typeof source.enabled !== 'boolean' || typeof source.lastRefreshStatus !== 'string'
                || !Number.isSafeInteger(source.channelCount) || source.channelCount < 0) {
                return null;
            }
            return Object.freeze({
                id: source.id,
                name: source.name.slice(0, 100),
                type: source.type,
                enabled: source.enabled,
                lastRefreshStatus: source.lastRefreshStatus,
                lastRefreshError: typeof source.lastRefreshError === 'string' ? source.lastRefreshError.slice(0, 240) : null,
                lastRefreshAt: typeof source.lastRefreshAt === 'string' ? source.lastRefreshAt : null,
                channelCount: source.channelCount,
            });
        }

        function receiveIptv(message) {
            const pending = iptvRequests.get(message.requestId);
            if (!pending || message.type !== pending.resultType || message.protocolVersion !== PROTOCOL_VERSION
                || typeof message.ok !== 'boolean') {
                return;
            }
            iptvRequests.delete(message.requestId);
            const detail = {
                action: pending.action,
                ok: message.ok,
                error: message.ok ? null : (typeof message.error === 'string' ? message.error.slice(0, 240) : 'Operação indisponível.'),
            };
            if (pending.action === 'list' && Array.isArray(message.sources)) {
                detail.sources = message.sources.map(safeSource).filter(Boolean);
            } else if (['add-url', 'pick-file', 'refresh'].includes(pending.action)) {
                detail.cancelled = message.cancelled === true;
                detail.source = safeSource(message.source);
            } else if (pending.action === 'remove') {
                detail.removed = message.removed === true;
            } else if (pending.action === 'groups' && Array.isArray(message.groups)) {
                detail.groups = message.groups.filter(function (group) { return typeof group === 'string'; }).map(function (group) { return group.slice(0, 240); });
            } else if (pending.action === 'search' && Array.isArray(message.channels)) {
                detail.channels = message.channels.filter(function (channel) {
                    return channel && positiveId(channel.id) && typeof channel.name === 'string';
                }).map(function (channel) {
                    return Object.freeze({
                        id: channel.id,
                        name: channel.name.slice(0, 240),
                        groupName: typeof channel.groupName === 'string' ? channel.groupName.slice(0, 240) : null,
                        logoUrl: typeof channel.logoUrl === 'string' ? channel.logoUrl.slice(0, 1024) : null,
                        tvgId: typeof channel.tvgId === 'string' ? channel.tvgId.slice(0, 240) : null,
                    });
                });
                detail.offset = Number.isSafeInteger(message.offset) ? message.offset : 0;
                detail.limit = Number.isSafeInteger(message.limit) ? message.limit : 50;
                detail.hasMore = message.hasMore === true;
            } else if (['media-start', 'media-stop', 'media-status'].includes(pending.action)) {
                Object.assign(detail, safeMediaState(message));
            }
            target.dispatchEvent(new target.CustomEvent('semyra:iptv-result', {detail: Object.freeze(detail)}));
        }

        function safeMediaState(message) {
            const validStates = ['idle', 'preparing', 'streaming', 'reconnecting', 'failed'];
            return {
                state: validStates.includes(message.state) ? message.state : 'failed',
                channelId: positiveId(message.channelId),
                channelName: typeof message.channelName === 'string' ? message.channelName.slice(0, 240) : null,
                attempt: Number.isSafeInteger(message.attempt) && message.attempt >= 0 ? message.attempt : 0,
                errorCode: typeof message.errorCode === 'string' ? message.errorCode.slice(0, 64) : null,
            };
        }

        function receiveMediaState(message) {
            if (!ready || message.protocolVersion !== PROTOCOL_VERSION) {
                return;
            }
            target.dispatchEvent(new target.CustomEvent('semyra:iptv-media-state', {
                detail: Object.freeze(safeMediaState(message)),
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
                case HOST_AUTHORIZE_RESULT_TYPE:
                    receiveHostAuthorize(message);
                    break;
                case HOST_CLEAR_RESULT_TYPE:
                    receiveHostClear(message);
                    break;
                case IPTV_MEDIA_STATE_TYPE:
                    receiveMediaState(message);
                    break;
                default:
                    if (Object.values(IPTV_COMMANDS).some(function (command) { return command[1] === message.type; })) {
                        receiveIptv(message);
                    }
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
            if (typeof target.addEventListener === 'function') {
                target.addEventListener('semyra:host-authorization-request', requestAuthorization);
                target.addEventListener('semyra:host-clear-request', requestClear);
                target.addEventListener('semyra:iptv-request', requestIptv);
            }
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
        HOST_AUTHORIZE_TYPE,
        HOST_AUTHORIZE_RESULT_TYPE,
        HOST_CLEAR_TYPE,
        HOST_CLEAR_RESULT_TYPE,
        PROTOCOL_VERSION,
        IPTV_COMMANDS,
        IPTV_MEDIA_STATE_TYPE,
        createDesktopBridge,
    });
}));
