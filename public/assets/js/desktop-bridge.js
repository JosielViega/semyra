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
    const HOST_LEAVE_TYPE = 'semyra.desktop.host.leave';
    const WINDOW_FULLSCREEN_STATUS_TYPE = 'semyra.desktop.window.fullscreen.status';
    const WINDOW_FULLSCREEN_STATUS_RESULT_TYPE = 'semyra.desktop.window.fullscreen.status-result';
    const WINDOW_FULLSCREEN_SET_TYPE = 'semyra.desktop.window.fullscreen.set';
    const WINDOW_FULLSCREEN_SET_RESULT_TYPE = 'semyra.desktop.window.fullscreen.set-result';
    const WINDOW_FULLSCREEN_CHANGED_TYPE = 'semyra.desktop.window.fullscreen.changed';
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
        'vod-refresh': ['semyra.desktop.iptv.vod.refresh', 'semyra.desktop.iptv.vod.refresh-result'],
        'vod-categories': ['semyra.desktop.iptv.vod.categories', 'semyra.desktop.iptv.vod.categories-result'],
        'vod-movies-search': ['semyra.desktop.iptv.vod.movies.search', 'semyra.desktop.iptv.vod.movies.search-result'],
        'vod-series-search': ['semyra.desktop.iptv.vod.series.search', 'semyra.desktop.iptv.vod.series.search-result'],
        'vod-series-details': ['semyra.desktop.iptv.vod.series.details', 'semyra.desktop.iptv.vod.series.details-result'],
        'vod-continue': ['semyra.desktop.iptv.vod.continue.list', 'semyra.desktop.iptv.vod.continue.list-result'],
        'vod-continue-dismiss': ['semyra.desktop.iptv.vod.continue-dismiss', 'semyra.desktop.iptv.vod.continue-dismiss-result'],
        'vod-view-start': ['semyra.desktop.iptv.vod.view-start', 'semyra.desktop.iptv.vod.view-start-result'],
        'vod-navigate': ['semyra.desktop.iptv.vod.navigate', 'semyra.desktop.iptv.vod.navigate-result'],
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
        'publish-control': ['semyra.desktop.media.publish-control', 'semyra.desktop.media.publish-control-result'],
    });
    const IPTV_MEDIA_STATE_TYPE = 'semyra.desktop.iptv.media.state';
    const SCREEN_COMMANDS = Object.freeze({
        targets: ['semyra.desktop.screen.targets', 'semyra.desktop.screen.targets-result'],
        preview: ['semyra.desktop.screen.preview', 'semyra.desktop.screen.preview-result'],
        'preview-cancel': ['semyra.desktop.screen.preview.cancel', 'semyra.desktop.screen.preview.cancel-result'],
        start: ['semyra.desktop.screen.publish.start', 'semyra.desktop.screen.publish.start-result'],
        stop: ['semyra.desktop.screen.publish.stop', 'semyra.desktop.screen.publish.stop-result'],
        status: ['semyra.desktop.screen.publish.status', 'semyra.desktop.screen.publish.status-result'],
    });
    const SCREEN_PUBLISH_STATE_TYPE = 'semyra.desktop.screen.publish.state';
    const IPTV_VOD_PROGRESS_CHANGED_TYPE = 'semyra.desktop.iptv.vod.progress-changed';
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
        let index = 2;
        if (capabilities[index] === 'window.fullscreen') index += 1;
        if (capabilities.length === index) return true;
        if (capabilities[index] !== 'iptv.sources' || capabilities[index + 1] !== 'iptv.catalog') return false;
        index += 2;
        if (capabilities[index] === 'iptv.local-view') index += 1;
        if (capabilities[index] === 'media.whip' && capabilities[index + 1] === 'livekit.publish') {
            index += 2;
            if (capabilities[index] === 'livekit.publish-vod') index += 1;
        }
        if (capabilities[index] === 'screen.targets' && capabilities[index + 1] === 'screen.publish') {
            index += 2;
            if (capabilities[index] === 'screen.audio') index += 1;
        }
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
        let windowFullscreenRequestId = null;
        let webview = null;
        let listening = false;
        let rememberControl = null;
        let rememberInteracted = false;
        const iptvRequests = new Map();
        const screenRequests = new Map();

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
                permission: authorized && ['media.publish', 'screen.publish'].includes(authorization.permission)
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
                || !['media.publish', 'screen.publish'].includes(detail.permission)
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

        function requestHostLeave() {
            if (!ready || !webview) return;
            webview.postMessage({type: HOST_LEAVE_TYPE, requestId: createRequestId(target)});
        }

        function requestWindowFullscreen(event) {
            if (!ready || !webview || !hostSnapshot
                || !hostSnapshot.capabilities.includes('window.fullscreen')) return;
            const enabled = event?.detail?.enabled;
            if (enabled !== undefined && typeof enabled !== 'boolean') return;
            windowFullscreenRequestId = createRequestId(target);
            webview.postMessage(enabled === undefined
                ? {type: WINDOW_FULLSCREEN_STATUS_TYPE, requestId: windowFullscreenRequestId}
                : {type: WINDOW_FULLSCREEN_SET_TYPE, requestId: windowFullscreenRequestId, enabled});
        }

        function receiveWindowFullscreen(message) {
            const changed = message.type === WINDOW_FULLSCREEN_CHANGED_TYPE;
            const result = message.type === WINDOW_FULLSCREEN_STATUS_RESULT_TYPE
                || message.type === WINDOW_FULLSCREEN_SET_RESULT_TYPE;
            if (!ready || message.protocolVersion !== PROTOCOL_VERSION
                || (!changed && (!result || message.requestId !== windowFullscreenRequestId))) return;
            if (result) windowFullscreenRequestId = null;
            const ok = changed || message.ok === true;
            const detail = Object.freeze({
                ok,
                enabled: ok && message.enabled === true,
                error: ok ? null : (typeof message.error === 'string'
                    ? message.error.slice(0, 64) : 'window_fullscreen_failed'),
            });
            target.dispatchEvent(new target.CustomEvent('semyra:window-fullscreen-state', {detail}));
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
            } else if (action === 'remove' || action === 'refresh' || action === 'vod-refresh' || action === 'groups') {
                request.sourceId = positiveId(detail.sourceId);
                if (request.sourceId === null) {
                    return;
                }
            } else if (action === 'search' || action === 'favorites' || action === 'vod-movies-search' || action === 'vod-series-search') {
                request.sourceId = positiveId(detail.sourceId);
                request.offset = Number.isSafeInteger(detail.offset) && detail.offset >= 0 ? detail.offset : 0;
                const maximum = action === 'favorites' ? 50 : 100;
                request.limit = Number.isSafeInteger(detail.limit) && detail.limit >= 1 && detail.limit <= maximum ? detail.limit : 50;
                if (action === 'search') {
                    request.query = typeof detail.query === 'string' && detail.query.length <= 120 ? detail.query : '';
                    request.group = typeof detail.group === 'string' && detail.group.length <= 240 && detail.group.length > 0
                        ? detail.group : null;
                }
                if (action === 'vod-movies-search' || action === 'vod-series-search') {
                    request.query = typeof detail.query === 'string' && detail.query.length <= 120 ? detail.query : '';
                    request.category = typeof detail.category === 'string' && detail.category.length <= 128 && detail.category.length > 0 ? detail.category : null;
                }
                if (request.sourceId === null) {
                    return;
                }
            } else if (action === 'vod-categories') {
                request.sourceId = positiveId(detail.sourceId);
                request.mediaType = ['movie', 'series'].includes(detail.mediaType) ? detail.mediaType : null;
                if (request.sourceId === null || request.mediaType === null) return;
            } else if (action === 'vod-series-details') {
                request.seriesId = positiveId(detail.seriesId);
                request.refresh = detail.refresh === true;
                if (request.seriesId === null) return;
            } else if (action === 'vod-continue') {
                request.sourceId = positiveId(detail.sourceId);
                if (request.sourceId === null) return;
            } else if (action === 'vod-continue-dismiss') {
                request.sourceId = positiveId(detail.sourceId);
                request.mediaId = positiveId(detail.mediaId);
                request.kind = ['movie', 'series'].includes(detail.kind) ? detail.kind : null;
                if (request.sourceId === null || request.mediaId === null || request.kind === null) return;
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
                const vodPublish = action === 'publish-start' && ['movie', 'episode'].includes(detail.kind);
                const capability = vodPublish ? 'livekit.publish-vod'
                    : (action === 'publish-start' ? 'livekit.publish' : 'iptv.local-view');
                if (!hostSnapshot.capabilities.includes(capability)) {
                    return;
                }
                if (vodPublish) {
                    request.kind = detail.kind;
                    request.mediaId = positiveId(detail.mediaId);
                    if (request.mediaId === null) return;
                } else {
                    request.channelId = positiveId(detail.channelId);
                    if (request.channelId === null) return;
                }
            } else if (action === 'publish-control') {
                if (!hostSnapshot.capabilities.includes('livekit.publish-vod')
                    || !['play', 'pause', 'seek', 'snapshot'].includes(detail.command)
                    || typeof detail.transmissionInstanceId !== 'string'
                    || !/^[a-f0-9]{32}$/.test(detail.transmissionInstanceId)
                    || !Number.isSafeInteger(detail.transmissionRevision)
                    || detail.transmissionRevision < 1) return;
                request.action = detail.command;
                request.transmissionInstanceId = detail.transmissionInstanceId;
                request.transmissionRevision = detail.transmissionRevision;
                if (detail.command === 'seek') {
                    if (!Number.isSafeInteger(detail.positionMs) || detail.positionMs < 0 || detail.positionMs > 315576000000) return;
                    request.positionMs = detail.positionMs;
                }
            } else if (action === 'vod-view-start') {
                if (!hostSnapshot.capabilities.includes('iptv.local-view')) return;
                request.kind = ['movie', 'episode'].includes(detail.kind) ? detail.kind : null;
                request.mediaId = positiveId(detail.mediaId);
                if (request.kind === null || request.mediaId === null) return;
            } else if (action === 'vod-navigate') {
                if (!hostSnapshot.capabilities.includes('iptv.local-view')) return;
                request.direction = ['previous', 'next'].includes(detail.direction) ? detail.direction : null;
                if (request.direction === null) return;
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
            const timeoutId = ['favorites', 'recents', 'favorite-set', 'recent-remove', 'recents-clear', 'publish-control'].includes(action)
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

        function requestScreen(event) {
            const detail = event?.detail;
            const action = typeof detail?.action === 'string' ? detail.action : '';
            const command = SCREEN_COMMANDS[action];
            if (!command || !accountContextId || !hostSnapshot
                || !hostSnapshot.capabilities.includes('screen.publish')) return;
            const request = {type: command[0], requestId: createRequestId(target), accountContextId};
            if (action === 'preview') {
                if (typeof detail.targetId !== 'string' || !/^[a-f0-9]{32}$/.test(detail.targetId)) return;
                request.targetId = detail.targetId;
            } else if (action === 'start') {
                if (typeof detail.targetId !== 'string' || !/^[a-f0-9]{32}$/.test(detail.targetId)
                    || !['economy', 'standard', 'high'].includes(detail.quality)
                    || typeof detail.audio !== 'boolean'
                    || (detail.audio && !hostSnapshot.capabilities.includes('screen.audio'))) return;
                request.targetId = detail.targetId;
                request.quality = detail.quality;
                request.audio = detail.audio;
            }
            screenRequests.set(request.requestId, Object.freeze({action, resultType: command[1], accountContextId}));
            try { webview.postMessage(request); }
            catch (_) { screenRequests.delete(request.requestId); }
        }

        function receiveScreen(message) {
            const pending = screenRequests.get(message.requestId);
            if (!pending || message.type !== pending.resultType || message.protocolVersion !== PROTOCOL_VERSION
                || pending.accountContextId !== accountContextId || typeof message.ok !== 'boolean') return;
            screenRequests.delete(message.requestId);
            const detail = {action: pending.action, ok: message.ok,
                error: message.ok ? null : (typeof message.error === 'string' ? message.error.slice(0, 64) : 'screen_command_failed')};
            if (pending.action === 'targets' && Array.isArray(message.targets)) {
                detail.targets = message.targets.filter(function (item) {
                    return item && typeof item.id === 'string' && /^[a-f0-9]{32}$/.test(item.id)
                        && ['monitor', 'window'].includes(item.type) && typeof item.name === 'string'
                        && Number.isSafeInteger(item.width) && Number.isSafeInteger(item.height);
                }).map(function (item) { return Object.freeze({id: item.id, type: item.type,
                    name: item.name.slice(0, 160), width: item.width, height: item.height, available: item.available === true}); });
            } else if (pending.action === 'preview') {
                const dataUrl = typeof message.dataUrl === 'string' && message.dataUrl.length <= 360000
                    && /^data:image\/jpeg;base64,[A-Za-z0-9+/=]+$/.test(message.dataUrl) ? message.dataUrl : null;
                detail.state = dataUrl && Number.isSafeInteger(message.width) && Number.isSafeInteger(message.height)
                    && message.width > 0 && message.width <= 400 && message.height > 0 ? 'available' : 'unavailable';
                detail.dataUrl = detail.state === 'available' ? dataUrl : null;
                detail.width = detail.state === 'available' ? message.width : null;
                detail.height = detail.state === 'available' ? message.height : null;
            } else {
                detail.state = typeof message.state === 'string' ? message.state.slice(0, 32) : 'failed';
                detail.targetId = typeof message.targetId === 'string' ? message.targetId : null;
                detail.encoder = typeof message.encoder === 'string' ? message.encoder.slice(0, 32) : null;
                detail.audio = message.audio === true;
                detail.errorCode = typeof message.errorCode === 'string' ? message.errorCode.slice(0, 64) : null;
                detail.diagnosticCode = typeof message.diagnosticCode === 'string' ? message.diagnosticCode.slice(0, 64) : null;
            }
            target.dispatchEvent(new target.CustomEvent('semyra:screen-result', {detail: Object.freeze(detail)}));
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
                || !Number.isSafeInteger(source.channelCount) || source.channelCount < 0
                || (source.movieCount !== undefined && (!Number.isSafeInteger(source.movieCount) || source.movieCount < 0))
                || (source.seriesCount !== undefined && (!Number.isSafeInteger(source.seriesCount) || source.seriesCount < 0))
                || (source.vodSyncState !== undefined && !['idle','syncing','ready','error'].includes(source.vodSyncState))) {
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
                movieCount: source.movieCount || 0,
                seriesCount: source.seriesCount || 0,
                vodSyncState: source.vodSyncState || 'idle',
                vodSyncError: typeof source.vodSyncError === 'string' ? source.vodSyncError.slice(0, 240) : null,
                vodLastSyncedAt: typeof source.vodLastSyncedAt === 'string' ? source.vodLastSyncedAt : null,
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
            } else if (['add-url', 'add-xtream', 'pick-file', 'refresh', 'vod-refresh'].includes(pending.action)) {
                detail.cancelled = message.cancelled === true;
                detail.source = safeSource(message.source);
            } else if (pending.action === 'remove') {
                detail.removed = message.removed === true;
            } else if (pending.action === 'vod-categories' && Array.isArray(message.categories)) {
                detail.categories = message.categories.filter(function (item) { return item && positiveId(item.id) && ['movie','series'].includes(item.mediaType) && typeof item.externalCategoryId === 'string' && typeof item.name === 'string'; }).map(function(item){return Object.freeze({id:item.id,mediaType:item.mediaType,externalCategoryId:item.externalCategoryId.slice(0,128),name:item.name.slice(0,240),displayOrder:Number.isSafeInteger(item.displayOrder)?item.displayOrder:0});});
            } else if (pending.action === 'vod-series-details' && positiveId(message.seriesId) && Array.isArray(message.episodes)) {
                detail.seriesId=message.seriesId; detail.lastSyncedAt=typeof message.lastSyncedAt==='string'?message.lastSyncedAt:null; detail.stale=message.stale===true; detail.episodes=message.episodes.filter(function(item){return item&&positiveId(item.id)&&Number.isSafeInteger(item.seasonNumber)&&typeof item.name==='string';}).map(function(item){return Object.freeze({id:item.id,seasonNumber:item.seasonNumber,episodeNumber:Number.isSafeInteger(item.episodeNumber)?item.episodeNumber:null,name:item.name.slice(0,240),duration:typeof item.duration==='string'?item.duration.slice(0,40):null,plot:typeof item.plot==='string'?item.plot.slice(0,2000):null,position:safeSeconds(item.position),progressPercent:safePercent(item.progressPercent),completed:item.completed===true,continueHere:item.continueHere===true});});
            } else if (pending.action === 'vod-continue' && Array.isArray(message.movies) && Array.isArray(message.series)) {
                detail.movies = message.movies.filter(function(item){return item&&positiveId(item.id)&&typeof item.name==='string';}).map(function(item){return Object.freeze({id:item.id,name:item.name.slice(0,240),posterUrl:safeUrl(item.posterUrl),year:typeof item.year==='string'?item.year.slice(0,16):null,position:safeSeconds(item.position),duration:safeSeconds(item.duration),progressPercent:safePercent(item.progressPercent),lastWatchedAt:safeDate(item.lastWatchedAt)});});
                detail.series = message.series.filter(function(item){return item&&positiveId(item.seriesId)&&positiveId(item.episodeId)&&typeof item.name==='string'&&Number.isSafeInteger(item.seasonNumber);}).map(function(item){return Object.freeze({seriesId:item.seriesId,name:item.name.slice(0,240),posterUrl:safeUrl(item.posterUrl),episodeId:item.episodeId,seasonNumber:item.seasonNumber,episodeNumber:Number.isSafeInteger(item.episodeNumber)?item.episodeNumber:null,episodeTitle:typeof item.episodeTitle==='string'?item.episodeTitle.slice(0,240):'',position:safeSeconds(item.position),duration:safeSeconds(item.duration),progressPercent:safePercent(item.progressPercent),completedPrevious:item.completedPrevious===true,lastWatchedAt:safeDate(item.lastWatchedAt)});});
            } else if (pending.action === 'vod-continue-dismiss') {
                detail.dismissed = message.dismissed === true;
            } else if (['vod-movies-search','vod-series-search'].includes(pending.action) && Array.isArray(message.items)) {
                detail.items=message.items.filter(function(item){return item&&positiveId(item.id)&&typeof item.name==='string';}).map(function(item){return Object.freeze({id:item.id,name:item.name.slice(0,240),categoryId:typeof item.categoryId==='string'?item.categoryId.slice(0,128):null,posterUrl:typeof item.posterUrl==='string'?item.posterUrl.slice(0,1024):null,year:typeof item.year==='string'?item.year.slice(0,16):null,rating:typeof item.rating==='string'?item.rating.slice(0,16):null,genre:typeof item.genre==='string'?item.genre.slice(0,240):null,plot:typeof item.plot==='string'?item.plot.slice(0,2000):null,releaseDate:typeof item.releaseDate==='string'?item.releaseDate.slice(0,40):null});}); detail.offset=Number.isSafeInteger(message.offset)?message.offset:0;detail.limit=Number.isSafeInteger(message.limit)?message.limit:50;detail.hasMore=message.hasMore===true;
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
            } else if (['view-start', 'vod-view-start', 'vod-navigate', 'view-stop', 'view-status', 'player-control', 'player-geometry', 'player-fullscreen', 'publish-start', 'publish-stop', 'publish-status', 'publish-control'].includes(pending.action)) {
                Object.assign(detail, safeMediaState(message));
                if (['view-start', 'vod-view-start'].includes(pending.action)
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
                vodContentKind: ['movie', 'episode'].includes(message.vodContentKind) ? message.vodContentKind : null,
                canPreviousEpisode: message.canPreviousEpisode === true,
                canNextEpisode: message.canNextEpisode === true,
            };
        }

        function safeSeconds(value) {
            return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : 0;
        }

        function safePercent(value) {
            return typeof value === 'number' && Number.isFinite(value) ? Math.min(100, Math.max(0, value)) : 0;
        }

        function safeUrl(value) {
            return typeof value === 'string' && /^https?:\/\//i.test(value) ? value.slice(0, 1024) : null;
        }

        function safeDate(value) {
            return typeof value === 'string' && value.length <= 64 ? value : null;
        }

        function receiveMediaState(message) {
            if (!ready || message.protocolVersion !== PROTOCOL_VERSION || message.accountContextId !== accountContextId) {
                return;
            }
            target.dispatchEvent(new target.CustomEvent('semyra:iptv-media-state', {
                detail: Object.freeze(safeMediaState(message)),
            }));
        }

        function receiveScreenPublishState(message) {
            if (!ready || message.protocolVersion !== PROTOCOL_VERSION
                || message.accountContextId !== accountContextId) return;
            const validStates = ['idle', 'preparing', 'connecting', 'streaming', 'stopping', 'cancelled', 'failed'];
            const detail = Object.freeze({
                state: validStates.includes(message.state) ? message.state : 'failed',
                targetId: typeof message.targetId === 'string' ? message.targetId.slice(0, 64) : null,
                targetType: ['monitor', 'window'].includes(message.targetType) ? message.targetType : null,
                quality: ['economy', 'standard', 'high'].includes(message.quality) ? message.quality : null,
                audio: message.audio === true,
                encoder: typeof message.encoder === 'string' ? message.encoder.slice(0, 32) : null,
                errorCode: typeof message.errorCode === 'string' ? message.errorCode.slice(0, 64) : null,
                diagnosticCode: typeof message.diagnosticCode === 'string' ? message.diagnosticCode.slice(0, 64) : null,
            });
            target.dispatchEvent(new target.CustomEvent('semyra:screen-state', {detail}));
        }

        function receiveVodProgressChanged(message) {
            if (!ready || message.protocolVersion !== PROTOCOL_VERSION || message.accountContextId !== accountContextId
                || !positiveId(message.sourceId) || !['movie', 'episode'].includes(message.mediaType)
                || !positiveId(message.mediaId) || typeof message.sessionId !== 'string'
                || !/^[a-f0-9]{32}$/.test(message.sessionId)) return;
            const detail = {
                accountContextId,
                sourceId: message.sourceId,
                mediaType: message.mediaType,
                mediaId: message.mediaId,
                seriesId: positiveId(message.seriesId),
                positionSeconds: safeSeconds(message.positionSeconds),
                durationSeconds: safeSeconds(message.durationSeconds),
                progressPercent: safePercent(message.progressPercent),
                completed: message.completed === true,
                seasonNumber: Number.isSafeInteger(message.seasonNumber) ? message.seasonNumber : null,
                episodeNumber: Number.isSafeInteger(message.episodeNumber) ? message.episodeNumber : null,
                sessionId: message.sessionId,
            };
            target.dispatchEvent(new target.CustomEvent('semyra:iptv-vod-progress-changed', {
                detail: Object.freeze(detail),
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
                case WINDOW_FULLSCREEN_STATUS_RESULT_TYPE:
                case WINDOW_FULLSCREEN_SET_RESULT_TYPE:
                case WINDOW_FULLSCREEN_CHANGED_TYPE:
                    receiveWindowFullscreen(message);
                    break;
                case ACCOUNT_ACTIVATE_RESULT_TYPE:
                case ACCOUNT_CLEAR_RESULT_TYPE:
                    receiveAccountResult(message);
                    break;
                case IPTV_MEDIA_STATE_TYPE:
                    receiveMediaState(message);
                    break;
                case SCREEN_PUBLISH_STATE_TYPE:
                    receiveScreenPublishState(message);
                    break;
                case IPTV_VOD_PROGRESS_CHANGED_TYPE:
                    receiveVodProgressChanged(message);
                    break;
                default:
                    if (Object.values(SCREEN_COMMANDS).some(function (command) { return command[1] === message.type; })) {
                        receiveScreen(message);
                        break;
                    }
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
                target.addEventListener('semyra:host-leave-request', requestHostLeave);
                target.addEventListener('semyra:window-fullscreen-request', requestWindowFullscreen);
                target.addEventListener('semyra:iptv-request', requestIptv);
                target.addEventListener('semyra:screen-request', requestScreen);
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
        HOST_LEAVE_TYPE,
        WINDOW_FULLSCREEN_STATUS_TYPE,
        WINDOW_FULLSCREEN_STATUS_RESULT_TYPE,
        WINDOW_FULLSCREEN_SET_TYPE,
        WINDOW_FULLSCREEN_SET_RESULT_TYPE,
        WINDOW_FULLSCREEN_CHANGED_TYPE,
        ACCOUNT_ACTIVATE_TYPE,
        ACCOUNT_ACTIVATE_RESULT_TYPE,
        ACCOUNT_CLEAR_TYPE,
        ACCOUNT_CLEAR_RESULT_TYPE,
        validCapabilities,
        PROTOCOL_VERSION,
        IPTV_COMMANDS,
        SCREEN_COMMANDS,
        SCREEN_PUBLISH_STATE_TYPE,
        IPTV_MEDIA_STATE_TYPE,
        PLAYER_CONTROLS_VISIBILITY_TYPE,
        createDesktopBridge,
    });
}));
