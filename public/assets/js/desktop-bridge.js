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
    const ACCOUNT_ACTIVATE_TYPE = 'semyra.desktop.account.activate';
    const ACCOUNT_ACTIVATE_RESULT_TYPE = 'semyra.desktop.account.activate-result';
    const ACCOUNT_CLEAR_TYPE = 'semyra.desktop.account.clear';
    const ACCOUNT_CLEAR_RESULT_TYPE = 'semyra.desktop.account.clear-result';
    const IPTV_RESPONSE_TIMEOUT_MS = 8000;
    const IPTV_COMMANDS = Object.freeze({
        list: ['semyra.desktop.iptv.sources.list', 'semyra.desktop.iptv.sources.list-result'],
        'add-url': ['semyra.desktop.iptv.sources.add', 'semyra.desktop.iptv.sources.add-result'],
        'add-xtream': ['semyra.desktop.iptv.sources.add-xtream', 'semyra.desktop.iptv.sources.add-xtream-result'],
        remove: ['semyra.desktop.iptv.sources.remove', 'semyra.desktop.iptv.sources.remove-result'],
        refresh: ['semyra.desktop.iptv.sources.refresh', 'semyra.desktop.iptv.sources.refresh-result'],
        'pick-file': ['semyra.desktop.iptv.sources.pick-file', 'semyra.desktop.iptv.sources.pick-file-result'],
        groups: ['semyra.desktop.iptv.groups.list', 'semyra.desktop.iptv.groups.list-result'],
        search: ['semyra.desktop.iptv.channels.search', 'semyra.desktop.iptv.channels.search-result'],
        favorites: ['semyra.desktop.iptv.favorites.list', 'semyra.desktop.iptv.favorites.list-result'],
        recents: ['semyra.desktop.iptv.recents.list', 'semyra.desktop.iptv.recents.list-result'],
        'recent-remove': ['semyra.desktop.iptv.recent.remove', 'semyra.desktop.iptv.recent.remove-result'],
        'recents-clear': ['semyra.desktop.iptv.recents.clear', 'semyra.desktop.iptv.recents.clear-result'],
        'favorite-set': ['semyra.desktop.iptv.favorite.set', 'semyra.desktop.iptv.favorite.set-result'],
        'view-start': ['semyra.desktop.iptv.view.start', 'semyra.desktop.iptv.view.start-result'],
        'view-stop': ['semyra.desktop.iptv.view.stop', 'semyra.desktop.iptv.view.stop-result'],
        'view-status': ['semyra.desktop.iptv.view.status', 'semyra.desktop.iptv.view.status-result'],
        'player-control': ['semyra.desktop.iptv.player.control', 'semyra.desktop.iptv.player.control-result'],
        'player-geometry': ['semyra.desktop.player.geometry', 'semyra.desktop.player.geometry-result'],
        'player-fullscreen': ['semyra.desktop.player.fullscreen', 'semyra.desktop.player.fullscreen-result'],
        'publish-start': ['semyra.desktop.iptv.publish.start', 'semyra.desktop.iptv.publish.start-result'],
        'publish-stop': ['semyra.desktop.iptv.publish.stop', 'semyra.desktop.iptv.publish.stop-result'],
        'publish-status': ['semyra.desktop.iptv.publish.status', 'semyra.desktop.iptv.publish.status-result'],
    });
    const IPTV_MEDIA_STATE_TYPE = 'semyra.desktop.iptv.media.state';
    const PROTOCOL_VERSION = 1;
    const PLAYER_USER_ACTIVITY_TYPE = 'semyra.desktop.player.user-activity';
    const PLAYER_CONTROLS_VISIBILITY_TYPE = 'semyra.desktop.player.controls-visibility';
    const REMEMBER_PREFERENCE_KEY = 'semyra.desktop.remember';
    const SESSION_MARKER_KEY = 'semyra.desktop.session';

    function validCapabilities(capabilities) {
        if (!Array.isArray(capabilities) || new Set(capabilities).size !== capabilities.length
            || capabilities[0] !== 'host.status' || capabilities[1] !== 'host.authorize') {
            return false;
        }
        if (capabilities.length === 2) return true;
        if (capabilities[2] !== 'iptv.sources' || capabilities[3] !== 'iptv.catalog') return false;
        let index = 4;
        if (capabilities[index] === 'iptv.local-view') index += 1;
        if (capabilities[index] === 'media.whip' && capabilities[index + 1] === 'livekit.publish') index += 2;
        return index === capabilities.length;
    }

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
        let accountRequestId = null;
        let accountAction = null;
        let accountContextId = null;
        let webview = null;
        let listening = false;
        let rememberControl = null;
        let rememberInteracted = false;
        const iptvRequests = new Map();

        function dispatchIptvError(pending, error) {
            const detail = {action: pending.action, ok: false, error};
            if (pending.clientRequestId !== null) detail.clientRequestId = pending.clientRequestId;
            target.dispatchEvent(new target.CustomEvent('semyra:iptv-result', {detail: Object.freeze(detail)}));
        }

        function clearIptvRequest(requestId) {
            const pending = iptvRequests.get(requestId);
            if (!pending) return null;
            iptvRequests.delete(requestId);
            if (pending.timeoutId !== null && typeof target.clearTimeout === 'function') {
                target.clearTimeout(pending.timeoutId);
            }
            return pending;
        }

        function clearIptvRequests(error) {
            Array.from(iptvRequests.keys()).forEach(function (requestId) {
                const pending = clearIptvRequest(requestId);
                if (pending && error) dispatchIptvError(pending, error);
            });
        }

        function expireIptvRequest(requestId) {
            const pending = clearIptvRequest(requestId);
            if (!pending) return;
            dispatchIptvError(pending, 'desktop_bridge_timeout');
        }

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

            void resolveAccountContext();
        }

        async function resolveAccountContext() {
            let profileId = null;
            try {
                const response = await target.fetch('/desktop/account-context', {
                    method: 'GET', credentials: 'same-origin', cache: 'no-store',
                    headers: {'Accept': 'application/json'},
                });
                const payload = await response.json();
                if (response.ok && typeof payload?.profile_id === 'string' && /^[a-f0-9]{64}$/.test(payload.profile_id)) {
                    profileId = payload.profile_id;
                }
            } catch (_) {
                profileId = null;
            }
            accountRequestId = createRequestId(target);
            accountAction = profileId === null ? 'clear' : 'activate';
            webview.postMessage(profileId === null
                ? {type: ACCOUNT_CLEAR_TYPE, requestId: accountRequestId}
                : {type: ACCOUNT_ACTIVATE_TYPE, requestId: accountRequestId, profileId});
        }

        function requestHostStatus() {
            hostRequestId = createRequestId(target);
            webview.postMessage({type: HOST_STATUS_TYPE, requestId: hostRequestId});
        }

        function receiveAccountResult(message) {
            if (message.requestId !== accountRequestId || message.protocolVersion !== PROTOCOL_VERSION) return;
            if (accountAction === 'activate') {
                if (message.type !== ACCOUNT_ACTIVATE_RESULT_TYPE || message.activated !== true
                    || typeof message.accountContextId !== 'string' || !/^[a-f0-9]{32}$/.test(message.accountContextId)) {
                    clearIptvRequests('account_context_changed');
                    accountRequestId = createRequestId(target);
                    accountAction = 'clear';
                    accountContextId = null;
                    webview.postMessage({type: ACCOUNT_CLEAR_TYPE, requestId: accountRequestId});
                    return;
                }
                if (accountContextId !== null && accountContextId !== message.accountContextId) {
                    clearIptvRequests('account_context_changed');
                }
                accountContextId = message.accountContextId;
            } else if (message.type === ACCOUNT_CLEAR_RESULT_TYPE && message.cleared === true) {
                clearIptvRequests('account_context_changed');
                accountContextId = null;
            } else {
                return;
            }
            accountRequestId = null;
            accountAction = null;
            requestHostStatus();
        }

        function receiveHostStatus(message) {
            const host = message.host;
            const capabilitiesValid = host && typeof host === 'object' && validCapabilities(host.capabilities);
            if (!ready
                || hostSnapshot !== null
                || message.requestId !== hostRequestId
                || message.protocolVersion !== PROTOCOL_VERSION
                || !host
                || typeof host !== 'object'
                || host.state !== 'ready'
                || !capabilitiesValid
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
            if (accountContextId !== null && host.capabilities.includes('iptv.sources')
                && document && typeof document.querySelectorAll === 'function') {
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
            if (!accountContextId || !hostSnapshot
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

            const request = {type: command[0], requestId: createRequestId(target), accountContextId};
            if (action === 'add-url') {
                if (typeof detail.name !== 'string' || detail.name.length < 1 || detail.name.length > 100
                    || typeof detail.location !== 'string' || detail.location.length < 1 || detail.location.length > 4096) {
                    return;
                }
                request.name = detail.name;
                request.location = detail.location;
                request.sourceType = 'm3u_url';
            } else if (action === 'add-xtream') {
                if (typeof detail.name !== 'string' || detail.name.length < 1 || detail.name.length > 100
                    || typeof detail.baseUrl !== 'string' || detail.baseUrl.length < 1 || detail.baseUrl.length > 2048
                    || typeof detail.username !== 'string' || detail.username.length < 1 || detail.username.length > 512
                    || typeof detail.password !== 'string' || detail.password.length < 1 || detail.password.length > 512) {
                    return;
                }
                request.name = detail.name;
                request.baseUrl = detail.baseUrl;
                request.username = detail.username;
                request.password = detail.password;
            } else if (action === 'remove' || action === 'refresh' || action === 'groups') {
                request.sourceId = positiveId(detail.sourceId);
                if (request.sourceId === null) {
                    return;
                }
            } else if (action === 'search' || action === 'favorites') {
                request.sourceId = positiveId(detail.sourceId);
                request.offset = Number.isSafeInteger(detail.offset) && detail.offset >= 0 ? detail.offset : 0;
                const maximum = action === 'search' ? 100 : 50;
                request.limit = Number.isSafeInteger(detail.limit) && detail.limit >= 1 && detail.limit <= maximum ? detail.limit : 50;
                if (action === 'search') {
                    request.query = typeof detail.query === 'string' && detail.query.length <= 120 ? detail.query : '';
                    request.group = typeof detail.group === 'string' && detail.group.length <= 240 && detail.group.length > 0
                        ? detail.group : null;
                }
                if (request.sourceId === null) {
                    return;
                }
            } else if (action === 'recents') {
                request.sourceId = positiveId(detail.sourceId);
                if (request.sourceId === null) return;
            } else if (action === 'recent-remove') {
                request.sourceId = positiveId(detail.sourceId);
                request.recentId = positiveId(detail.recentId);
                if (request.sourceId === null || request.recentId === null) return;
            } else if (action === 'recents-clear') {
                request.sourceId = positiveId(detail.sourceId);
                if (request.sourceId === null) return;
            } else if (action === 'favorite-set') {
                request.channelId = positiveId(detail.channelId);
                request.favorite = detail.favorite === true;
                if (request.channelId === null || typeof detail.favorite !== 'boolean') return;
            } else if (action === 'publish-start' || action === 'view-start') {
                const capability = action === 'publish-start' ? 'livekit.publish'
                    : 'iptv.local-view';
                if (!hostSnapshot.capabilities.includes(capability)) {
                    return;
                }
                request.channelId = positiveId(detail.channelId);
                if (request.channelId === null) {
                    return;
                }
            } else if (action === 'player-control') {
                if (typeof detail.sessionId !== 'string' || !/^[a-f0-9]{32}$/.test(detail.sessionId)
                    || !['pause', 'play', 'seek-relative', 'seek-absolute', 'mute', 'unmute', 'volume', 'stop'].includes(detail.command)) return;
                request.sessionId = detail.sessionId;
                request.command = detail.command;
                if (['seek-relative', 'seek-absolute', 'volume'].includes(detail.command)) {
                    if (typeof detail.value !== 'number' || !Number.isFinite(detail.value)) return;
                    request.value = detail.value;
                }
            } else if (action === 'player-geometry') {
                if (typeof detail.sessionId !== 'string' || !/^[a-f0-9]{32}$/.test(detail.sessionId)) return;
                const names = ['x', 'y', 'width', 'height', 'viewportWidth', 'viewportHeight'];
                if (names.some(function (name) { return typeof detail[name] !== 'number' || !Number.isFinite(detail[name]); })) return;
                request.sessionId = detail.sessionId;
                names.forEach(function (name) { request[name] = detail[name]; });
                if (typeof detail.visible !== 'boolean') return;
                request.visible = detail.visible;
            } else if (action === 'player-fullscreen') {
                if (typeof detail.sessionId !== 'string' || !/^[a-f0-9]{32}$/.test(detail.sessionId)
                    || typeof detail.fullscreen !== 'boolean') return;
                request.sessionId = detail.sessionId;
                request.fullscreen = detail.fullscreen;
            }

            const clientRequestId = typeof detail.clientRequestId === 'string' && /^[a-z0-9-]{1,64}$/.test(detail.clientRequestId)
                ? detail.clientRequestId : null;
            const timeoutId = ['favorites', 'recents', 'favorite-set', 'recent-remove', 'recents-clear'].includes(action)
                && typeof target.setTimeout === 'function'
                ? target.setTimeout(function () { expireIptvRequest(request.requestId); }, IPTV_RESPONSE_TIMEOUT_MS)
                : null;
            iptvRequests.set(request.requestId, Object.freeze({action, resultType: command[1], accountContextId, clientRequestId, timeoutId}));
            try {
                webview.postMessage(request);
            } catch (_) {
                const pending = clearIptvRequest(request.requestId);
                if (pending) dispatchIptvError(pending, 'desktop_bridge_unavailable');
            }
        }

        function reportPlayerControlsVisibility(event) {
            const detail = event && event.detail;
            if (!webview || !detail || typeof detail.hidden !== 'boolean') return;
            webview.postMessage({
                type: PLAYER_CONTROLS_VISIBILITY_TYPE,
                hidden: detail.hidden,
            });
        }

        function safeSource(source) {
            if (!source || !positiveId(source.id) || typeof source.name !== 'string'
                || !['m3u_url', 'm3u_file', 'xtream'].includes(source.type)
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
                || pending.accountContextId !== accountContextId || typeof message.ok !== 'boolean') {
                return;
            }
            clearIptvRequest(message.requestId);
            const detail = {
                action: pending.action,
                ok: message.ok,
                error: message.ok ? null : (typeof message.errorCode === 'string'
                    ? message.errorCode.slice(0, 64)
                    : (typeof message.error === 'string' ? message.error.slice(0, 240) : 'Operação indisponível.')),
            };
            if (pending.clientRequestId !== null) detail.clientRequestId = pending.clientRequestId;
            if (pending.action === 'list' && Array.isArray(message.sources)) {
                detail.sources = message.sources.map(safeSource).filter(Boolean);
            } else if (['add-url', 'add-xtream', 'pick-file', 'refresh'].includes(pending.action)) {
                detail.cancelled = message.cancelled === true;
                detail.source = safeSource(message.source);
            } else if (pending.action === 'remove') {
                detail.removed = message.removed === true;
            } else if (pending.action === 'groups' && Array.isArray(message.groups)) {
                detail.groups = message.groups.filter(function (group) { return typeof group === 'string'; }).map(function (group) { return group.slice(0, 240); });
            } else if (['search', 'favorites', 'recents'].includes(pending.action) && Array.isArray(message.channels)) {
                detail.channels = message.channels.map(pending.action === 'recents' ? safeRecent : safeChannel).filter(Boolean);
                detail.offset = Number.isSafeInteger(message.offset) ? message.offset : 0;
                detail.limit = Number.isSafeInteger(message.limit) ? message.limit : 50;
                detail.hasMore = message.hasMore === true;
            } else if (pending.action === 'favorite-set') {
                detail.channelId = positiveId(message.channelId);
                detail.favorite = message.favorite === true;
            } else if (pending.action === 'recent-remove') {
                detail.sourceId = positiveId(message.sourceId);
                detail.recentId = positiveId(message.recentId);
                detail.removed = message.removed === true;
            } else if (pending.action === 'recents-clear') {
                detail.sourceId = positiveId(message.sourceId);
                detail.removedCount = Number.isSafeInteger(message.removedCount) && message.removedCount >= 0
                    ? message.removedCount : null;
            } else if (['view-start', 'view-stop', 'view-status', 'player-control', 'player-geometry', 'player-fullscreen', 'publish-start', 'publish-stop', 'publish-status'].includes(pending.action)) {
                Object.assign(detail, safeMediaState(message));
                if (pending.action === 'view-start'
                    && message.playbackKind === 'native-mpv'
                    && typeof message.sessionId === 'string'
                    && /^[a-f0-9]{32}$/.test(message.sessionId)) {
                    detail.playbackKind = 'native-mpv';
                    detail.sessionId = message.sessionId;
                }
            }
            target.dispatchEvent(new target.CustomEvent('semyra:iptv-result', {detail: Object.freeze(detail)}));
        }

        function safeChannel(channel) {
            if (!channel || !positiveId(channel.id) || typeof channel.name !== 'string') return null;
            return Object.freeze({
                id: channel.id,
                name: channel.name.slice(0, 240),
                groupName: typeof channel.groupName === 'string' ? channel.groupName.slice(0, 240) : null,
                logoUrl: typeof channel.logoUrl === 'string' ? channel.logoUrl.slice(0, 1024) : null,
                tvgId: typeof channel.tvgId === 'string' ? channel.tvgId.slice(0, 240) : null,
                isFavorite: channel.isFavorite === true,
            });
        }

        function safeRecent(recent) {
            if (!recent || !positiveId(recent.recentId) || !positiveId(recent.channelId) || typeof recent.name !== 'string') return null;
            return Object.freeze({
                recentId: recent.recentId,
                id: recent.channelId,
                name: recent.name.slice(0, 240),
                groupName: typeof recent.groupName === 'string' ? recent.groupName.slice(0, 240) : null,
                logoUrl: typeof recent.logoUrl === 'string' ? recent.logoUrl.slice(0, 1024) : null,
                tvgId: typeof recent.tvgId === 'string' ? recent.tvgId.slice(0, 240) : null,
                isFavorite: recent.isFavorite === true,
            });
        }

        function safeMediaState(message) {
            const validStates = ['idle', 'preparing', 'streaming', 'reconnecting', 'loading', 'playing', 'paused', 'buffering', 'ended', 'failed'];
            return {
                state: validStates.includes(message.state) ? message.state : 'failed',
                channelId: positiveId(message.channelId),
                channelName: typeof message.channelName === 'string' ? message.channelName.slice(0, 240) : null,
                attempt: Number.isSafeInteger(message.attempt) && message.attempt >= 0 ? message.attempt : 0,
                errorCode: typeof message.errorCode === 'string' ? message.errorCode.slice(0, 64) : null,
                mode: ['publish', 'view'].includes(message.mode) ? message.mode : 'local',
                playbackKind: message.playbackKind === 'native-mpv' ? 'native-mpv' : null,
                sessionId: typeof message.sessionId === 'string' && /^[a-f0-9]{32}$/.test(message.sessionId) ? message.sessionId : null,
                position: typeof message.position === 'number' && Number.isFinite(message.position) && message.position >= 0 ? message.position : 0,
                duration: typeof message.duration === 'number' && Number.isFinite(message.duration) && message.duration >= 0 ? message.duration : 0,
                seekable: message.seekable === true,
                muted: message.muted === true,
                volume: typeof message.volume === 'number' && Number.isFinite(message.volume) ? Math.min(100, Math.max(0, message.volume)) : 100,
                container: typeof message.container === 'string' ? message.container.slice(0, 64) : null,
                videoCodec: typeof message.videoCodec === 'string' ? message.videoCodec.slice(0, 64) : null,
                audioCodec: typeof message.audioCodec === 'string' ? message.audioCodec.slice(0, 64) : null,
                mediaKind: ['live', 'vod'].includes(message.mediaKind) ? message.mediaKind : null,
                fullscreen: message.fullscreen === true,
                paused: message.paused === true,
                buffering: message.buffering === true,
            };
        }

        function receiveMediaState(message) {
            if (!ready || message.protocolVersion !== PROTOCOL_VERSION || message.accountContextId !== accountContextId) {
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
                case PLAYER_USER_ACTIVITY_TYPE:
                    target.dispatchEvent(new target.CustomEvent('semyra:player-user-activity', {detail: undefined}));
                    break;
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
                case ACCOUNT_ACTIVATE_RESULT_TYPE:
                case ACCOUNT_CLEAR_RESULT_TYPE:
                    receiveAccountResult(message);
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
                target.addEventListener('semyra:player-controls-visibility', reportPlayerControlsVisibility);
            }
            const logoutForm = target.document?.querySelector?.('form.account-logout[action="/logout"]');
            logoutForm?.addEventListener?.('submit', function () {
                accountContextId = null;
                clearIptvRequests('account_context_changed');
                webview.postMessage({type: ACCOUNT_CLEAR_TYPE, requestId: createRequestId(target)});
            });
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
        ACCOUNT_ACTIVATE_TYPE,
        ACCOUNT_ACTIVATE_RESULT_TYPE,
        ACCOUNT_CLEAR_TYPE,
        ACCOUNT_CLEAR_RESULT_TYPE,
        validCapabilities,
        PROTOCOL_VERSION,
        IPTV_COMMANDS,
        IPTV_MEDIA_STATE_TYPE,
        PLAYER_CONTROLS_VISIBILITY_TYPE,
        createDesktopBridge,
    });
}));
