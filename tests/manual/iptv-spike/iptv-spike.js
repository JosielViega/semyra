(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.IptvSpike = api;

    if (typeof document !== 'undefined') {
        document.addEventListener('DOMContentLoaded', () => api.initialize(document, root));
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const MAX_CATALOG_ITEMS = 50;
    const PUBLIC_HLS_TEST_URL = 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8';
    const SENSITIVE_QUERY_KEYS = ['username', 'user', 'password', 'pass', 'token', 'auth', 'key'];
    const MATRIX_LABELS = {
        xtreamAuth: 'Xtream Auth',
        apiCors: 'API CORS',
        hlsManifest: 'HLS Manifest',
        hlsSegments: 'HLS Segments',
        livePlayback: 'Live Playback',
        liveDvr: 'Live Seek/DVR',
        vodPlayback: 'VOD Playback',
        vodSeek: 'VOD Seek',
        httpsProvider: 'HTTPS Provider',
        mixedContent: 'Mixed Content',
        credentialsExposed: 'Credentials exposed',
    };

    function escapeRegExp(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function redactSensitive(value, knownSecrets) {
        let text = String(value == null ? '' : value);
        const secrets = Array.isArray(knownSecrets) ? knownSecrets : [];

        for (const secretValue of secrets) {
            const secret = String(secretValue || '');
            if (!secret) {
                continue;
            }
            for (const candidate of new Set([secret, encodeURIComponent(secret)])) {
                text = text.replace(new RegExp(escapeRegExp(candidate), 'g'), '***');
            }
        }

        const keys = SENSITIVE_QUERY_KEYS.join('|');
        text = text.replace(new RegExp('([?&](?:' + keys + ')=)[^&#\\s]*', 'gi'), '$1***');
        return text;
    }

    function detectUrlCredentials(value, knownSecrets) {
        try {
            const url = new URL(String(value || '').trim());
            if (url.username || url.password) {
                return 'yes';
            }
            for (const key of SENSITIVE_QUERY_KEYS) {
                if (url.searchParams.has(key) && url.searchParams.get(key) !== '') {
                    return 'yes';
                }
            }
            const decodedSegments = url.pathname.split('/').filter(Boolean).map((segment) => {
                try {
                    return decodeURIComponent(segment);
                } catch (_error) {
                    return segment;
                }
            });
            for (const secretValue of Array.isArray(knownSecrets) ? knownSecrets : []) {
                const secret = String(secretValue || '');
                if (secret && decodedSegments.includes(secret)) {
                    return 'yes';
                }
            }
            return 'no';
        } catch (_error) {
            return 'unknown';
        }
    }

    function parseM3u(source) {
        const lines = String(source || '').replace(/^\uFEFF/, '').split(/\r?\n/);
        const entries = [];
        let pending = null;

        for (const rawLine of lines) {
            const line = rawLine.trim();
            if (!line) {
                continue;
            }
            if (line.startsWith('#EXTINF:')) {
                const commaIndex = line.indexOf(',');
                const attributesPart = commaIndex >= 0 ? line.slice(0, commaIndex) : line;
                const displayName = commaIndex >= 0 ? line.slice(commaIndex + 1).trim() : '';
                const attributes = {};
                const pattern = /([\w-]+)="([^"]*)"/g;
                let match;
                while ((match = pattern.exec(attributesPart)) !== null) {
                    attributes[match[1].toLowerCase()] = match[2];
                }
                pending = {
                    tvgId: attributes['tvg-id'] || '',
                    tvgName: attributes['tvg-name'] || '',
                    tvgLogo: attributes['tvg-logo'] || '',
                    groupTitle: attributes['group-title'] || '',
                    name: displayName || attributes['tvg-name'] || 'Item sem nome',
                };
                continue;
            }
            if (line.startsWith('#')) {
                continue;
            }

            const metadata = pending || {
                tvgId: '', tvgName: '', tvgLogo: '', groupTitle: '', name: 'Item sem nome',
            };
            entries.push(Object.assign({}, metadata, {url: line}));
            pending = null;
        }

        return entries;
    }

    function classifyMediaUrl(value, requestedType) {
        const requested = String(requestedType || 'auto').toLowerCase();
        if (['hls', 'mp4', 'ts'].includes(requested)) {
            return requested;
        }
        let path = String(value || '').toLowerCase();
        try {
            path = new URL(path).pathname.toLowerCase();
        } catch (_error) {
            path = path.split(/[?#]/, 1)[0];
        }
        if (path.endsWith('.m3u8')) return 'hls';
        if (path.endsWith('.mp4')) return 'mp4';
        if (path.endsWith('.ts')) return 'ts';
        if (/\.(mkv|avi|m4v|webm|mov|flv|wmv)$/.test(path)) return 'other-vod';
        return 'unknown';
    }

    function detectedMediaType(value, requestedType) {
        const classified = classifyMediaUrl(value, 'auto');
        if (classified === 'hls') return 'hls-m3u8';
        if (classified === 'ts') return 'mpeg-ts';
        if (classified === 'mp4') return 'mp4';
        if (classified === 'other-vod') return 'other';
        return 'unknown';
    }

    function detectMixedContent(pageProtocol, providerUrl) {
        try {
            return String(pageProtocol).toLowerCase() === 'https:'
                && new URL(providerUrl).protocol.toLowerCase() === 'http:';
        } catch (_error) {
            return false;
        }
    }

    function providerProtocol(providerUrl) {
        try {
            return new URL(providerUrl).protocol.replace(':', '').toLowerCase();
        } catch (_error) {
            return 'unknown';
        }
    }

    function futureHttpsRisk(resourceProtocol) {
        if (resourceProtocol === 'http') return 'risk';
        if (resourceProtocol === 'https') return 'no-obvious-risk';
        return 'unknown';
    }

    function normalizeBaseUrl(value) {
        const url = new URL(String(value || '').trim());
        if (!['http:', 'https:'].includes(url.protocol)) {
            throw new Error('Use uma URL HTTP ou HTTPS válida.');
        }
        url.username = '';
        url.password = '';
        url.search = '';
        url.hash = '';
        return url.toString().replace(/\/$/, '');
    }

    function initialResults() {
        return Object.fromEntries(Object.keys(MATRIX_LABELS).map((key) => [key, 'N/A']));
    }

    function initialReportState() {
        return {
            sourceMode: 'xtream',
            providerProtocol: 'unknown',
            providerHttpsAdvertised: 'unknown',
            mixedContentRisk: false,
            xtreamAuth: 'not-tested',
            allowedFormats: [],
            apiCatalogCors: 'not-tested',
            liveTested: false,
            liveFormat: 'not-tested',
            hlsManifest: 'not-tested',
            hlsSegments: 'not-tested',
            playback: 'not-tested',
            resolution: 'unknown',
            liveDvr: 'unknown',
            seekable: 'unknown',
            vodTested: false,
            vodExtension: 'not-tested',
            vodPlayback: 'not-tested',
            vodSeek: 'not-tested',
            vodDuration: 'unknown',
            fatalErrorType: 'none',
            fatalErrorDetails: 'none',
            credentialsInPlaybackUrl: 'unknown',
            credentialsInPlaylistUrl: 'unknown',
            credentialsInMediaUrl: 'unknown',
            m3uPlaylistFetch: 'not-tested',
            m3uParsedItems: 0,
            remoteM3uProtocol: 'n/a',
            mediaTested: false,
            mediaProtocol: 'other',
            detectedMediaType: 'unknown',
            playbackMethod: 'none',
            playbackFailureStage: 'none',
            mediaErrorCode: 'none',
            hlsFatal: 'unknown',
            hlsErrorType: 'none',
            hlsErrorDetails: 'none',
            futureHttpsRisk: 'unknown',
            results: initialResults(),
        };
    }

    function safeList(values) {
        if (!Array.isArray(values)) return 'none';
        const safe = values
            .map((value) => String(value).toLowerCase())
            .filter((value) => /^[a-z0-9_-]{1,20}$/.test(value));
        return safe.length ? safe.join(', ') : 'none';
    }

    function reportValue(value, knownSecrets) {
        if (typeof value === 'boolean') return value ? 'yes' : 'no';
        if (value === null || value === undefined || value === '') return 'unknown';
        return redactSensitive(String(value), knownSecrets)
            .replace(/https?:\/\/[^\s]+/gi, '[redacted-url]')
            .replace(/[\r\n]+/g, ' ')
            .slice(0, 160);
    }

    function applyPlaybackFailure(currentState, evidence) {
        const next = Object.assign({}, currentState || {});
        const details = evidence || {};
        next.playback = 'fail';
        next.playbackFailureStage = details.stage || 'unknown';
        next.mediaErrorCode = details.mediaErrorCode == null ? 'none' : String(details.mediaErrorCode);
        next.hlsFatal = details.hlsFatal == null ? next.hlsFatal || 'unknown' : Boolean(details.hlsFatal);
        next.hlsErrorType = details.hlsErrorType || next.hlsErrorType || 'none';
        next.hlsErrorDetails = details.hlsErrorDetails || next.hlsErrorDetails || 'none';
        next.fatalErrorType = details.fatalErrorType
            || (details.mediaErrorCode != null ? 'browser-media-error' : 'playback-error');
        next.fatalErrorDetails = details.fatalErrorDetails
            || (details.mediaErrorCode != null ? 'MediaError.code=' + details.mediaErrorCode : 'unknown');
        if (next.fatalErrorType === 'none') next.fatalErrorType = 'playback-error';
        if (next.fatalErrorDetails === 'none') next.fatalErrorDetails = 'unknown';
        return next;
    }

    function buildSanitizedReport(state, capabilities, knownSecrets) {
        const s = Object.assign(initialReportState(), state || {});
        const c = Object.assign({
            browser: 'unknown', pageProtocol: 'unknown', mediaSource: false,
            managedMediaSource: false, hlsJs: false, nativeHls: '', mp4: '', avcAac: '', ts: '',
        }, capabilities || {});
        return [
            'IPTV SPIKE REPORT',
            'Browser: ' + reportValue(c.browser, knownSecrets),
            'Page protocol: ' + reportValue(c.pageProtocol, knownSecrets),
            'Source mode: ' + reportValue(s.sourceMode, knownSecrets),
            'Provider resource protocol: ' + reportValue(s.providerProtocol, knownSecrets),
            'Provider HTTPS advertised: ' + reportValue(s.providerHttpsAdvertised, knownSecrets),
            'Mixed content risk: ' + reportValue(s.mixedContentRisk, knownSecrets),
            'Future HTTPS Semyra risk: ' + reportValue(s.futureHttpsRisk, knownSecrets),
            '',
            'MSE: ' + reportValue(c.mediaSource, knownSecrets),
            'ManagedMediaSource: ' + reportValue(c.managedMediaSource, knownSecrets),
            'Hls.js supported: ' + reportValue(c.hlsJs, knownSecrets),
            'Native HLS: ' + reportValue(c.nativeHls || 'não anunciado', knownSecrets),
            'MP4: ' + reportValue(c.mp4 || 'não anunciado', knownSecrets),
            'MP4 AVC/AAC: ' + reportValue(c.avcAac || 'não anunciado', knownSecrets),
            'MPEG-TS direct: ' + reportValue(c.ts || 'não anunciado', knownSecrets),
            '',
            'Xtream auth: ' + reportValue(s.xtreamAuth, knownSecrets),
            'Allowed formats: ' + safeList(s.allowedFormats),
            'API CORS: ' + reportValue(s.apiCatalogCors, knownSecrets),
            '',
            'M3U playlist fetch: ' + reportValue(s.m3uPlaylistFetch, knownSecrets),
            'M3U parsed items: ' + reportValue(s.m3uParsedItems, knownSecrets),
            'Remote M3U protocol: ' + reportValue(s.remoteM3uProtocol, knownSecrets),
            'Credentials in playlist URL: ' + reportValue(s.credentialsInPlaylistUrl, knownSecrets),
            '',
            'Media tested: ' + reportValue(s.mediaTested, knownSecrets),
            'Media protocol: ' + reportValue(s.mediaProtocol, knownSecrets),
            'Detected media type: ' + reportValue(s.detectedMediaType, knownSecrets),
            'Playback method: ' + reportValue(s.playbackMethod, knownSecrets),
            'Media URL credentials: ' + reportValue(s.credentialsInMediaUrl, knownSecrets),
            '',
            'Live tested: ' + reportValue(s.liveTested, knownSecrets),
            'Live format: ' + reportValue(s.liveFormat, knownSecrets),
            'HLS manifest: ' + reportValue(s.hlsManifest, knownSecrets),
            'HLS media segments: ' + reportValue(s.hlsSegments, knownSecrets),
            'Playback: ' + reportValue(s.playback, knownSecrets),
            'Playback failure stage: ' + reportValue(s.playbackFailureStage, knownSecrets),
            'MediaError.code: ' + reportValue(s.mediaErrorCode, knownSecrets),
            'HLS fatal: ' + reportValue(s.hlsFatal, knownSecrets),
            'HLS error type: ' + reportValue(s.hlsErrorType, knownSecrets),
            'HLS error details: ' + reportValue(s.hlsErrorDetails, knownSecrets),
            'Resolution: ' + reportValue(s.resolution, knownSecrets),
            'Live/DVR: ' + reportValue(s.liveDvr, knownSecrets),
            'Seekable: ' + reportValue(s.seekable, knownSecrets),
            '',
            'VOD tested: ' + reportValue(s.vodTested, knownSecrets),
            'VOD extension: ' + reportValue(s.vodExtension, knownSecrets),
            'VOD playback: ' + reportValue(s.vodPlayback, knownSecrets),
            'VOD seek: ' + reportValue(s.vodSeek, knownSecrets),
            'VOD duration: ' + reportValue(s.vodDuration, knownSecrets),
            '',
            'Fatal error type: ' + reportValue(s.fatalErrorType, knownSecrets),
            'Fatal error details: ' + reportValue(s.fatalErrorDetails, knownSecrets),
        ].join('\n');
    }

    function initialize(doc, win) {
        const byId = (id) => doc.getElementById(id);
        const video = byId('video');
        let hls = null;
        let secrets = [];
        let xtreamContext = null;
        let state = initialReportState();
        let xtreamPlaybackWarningAccepted = false;
        let currentMediaKind = 'unknown';
        let loadedMetadataForCurrentMedia = false;
        const capabilities = {
            browser: win.navigator.userAgent,
            pageProtocol: win.location.protocol.replace(':', ''),
            mediaSource: typeof win.MediaSource !== 'undefined',
            managedMediaSource: typeof win.ManagedMediaSource !== 'undefined',
            hlsJs: typeof win.Hls !== 'undefined' && win.Hls.isSupported(),
            nativeHls: video.canPlayType('application/vnd.apple.mpegurl'),
            mp4: video.canPlayType('video/mp4'),
            avcAac: video.canPlayType('video/mp4; codecs="avc1.42E01E, mp4a.40.2"'),
            ts: video.canPlayType('video/mp2t'),
        };

        function safe(value) {
            return redactSensitive(value, secrets);
        }

        function setDefinitionList(element, rows) {
            element.replaceChildren();
            for (const [label, value] of rows) {
                const dt = doc.createElement('dt');
                const dd = doc.createElement('dd');
                dt.textContent = label;
                dd.textContent = safe(value);
                element.append(dt, dd);
            }
        }

        function capabilityLabel(value) {
            if (value === 'probably') return 'provável';
            if (value === 'maybe') return 'maybe';
            return 'não anunciado';
        }

        setDefinitionList(byId('browser-diagnostics'), [
            ['User agent', capabilities.browser],
            ['Protocolo da página', win.location.protocol],
            ['MediaSource', capabilities.mediaSource ? 'sim' : 'não'],
            ['ManagedMediaSource', capabilities.managedMediaSource ? 'sim' : 'não'],
            ['Hls.isSupported()', capabilities.hlsJs ? 'sim' : 'não'],
            ['HLS nativo', capabilityLabel(capabilities.nativeHls)],
            ['MP4', capabilityLabel(capabilities.mp4)],
            ['MP4 AVC/AAC', capabilityLabel(capabilities.avcAac)],
            ['MPEG-TS direto', capabilityLabel(capabilities.ts)],
        ]);

        function renderMatrix() {
            const body = byId('result-matrix');
            body.replaceChildren();
            for (const [key, label] of Object.entries(MATRIX_LABELS)) {
                const row = doc.createElement('tr');
                const name = doc.createElement('td');
                const result = doc.createElement('td');
                name.textContent = label;
                result.textContent = state.results[key] || 'N/A';
                row.append(name, result);
                body.append(row);
            }
        }

        function renderReport() {
            byId('sanitized-report').value = buildSanitizedReport(state, capabilities, secrets);
            renderMatrix();
        }

        function updateResult(key, value) {
            state.results[key] = value;
            renderReport();
        }

        function logEvent(name, detail) {
            const item = doc.createElement('li');
            item.textContent = detail ? name + ': ' + safe(detail) : name;
            const log = byId('event-log');
            log.prepend(item);
            while (log.children.length > 80) log.lastElementChild.remove();
        }

        function seekableDescription() {
            const ranges = [];
            for (let index = 0; index < video.seekable.length; index += 1) {
                ranges.push(video.seekable.start(index).toFixed(2) + '–' + video.seekable.end(index).toFixed(2));
            }
            return ranges.length ? ranges.join(', ') : 'none';
        }

        function renderVideoState() {
            const duration = Number.isFinite(video.duration) ? video.duration.toFixed(2) : String(video.duration || 'unknown');
            setDefinitionList(byId('video-state'), [
                ['Resolução', video.videoWidth + '×' + video.videoHeight],
                ['Duração', duration],
                ['Posição', Number(video.currentTime || 0).toFixed(2)],
                ['Seekable', seekableDescription()],
                ['Pausado', video.paused ? 'sim' : 'não'],
                ['readyState', String(video.readyState)],
                ['networkState', String(video.networkState)],
                ['MediaError.code', video.error ? String(video.error.code) : 'none'],
            ]);
            state.resolution = video.videoWidth && video.videoHeight ? video.videoWidth + 'x' + video.videoHeight : 'unknown';
            state.seekable = seekableDescription();
            renderReport();
        }

        function destroyPlayer() {
            if (hls) {
                hls.destroy();
                hls = null;
            }
            video.pause();
            video.removeAttribute('src');
            video.load();
            currentMediaKind = 'unknown';
            loadedMetadataForCurrentMedia = false;
        }

        function setProviderDiagnostics(url) {
            state.providerProtocol = providerProtocol(url);
            state.futureHttpsRisk = futureHttpsRisk(state.providerProtocol);
            state.mixedContentRisk = detectMixedContent(win.location.protocol, url);
            updateResult('httpsProvider', state.providerProtocol === 'https' ? 'YES' : state.providerProtocol === 'http' ? 'NO' : 'UNKNOWN');
            updateResult('mixedContent', state.providerProtocol === 'http' ? 'RISK' : state.mixedContentRisk ? 'RISK' : 'OK');
            if (state.providerProtocol === 'http') {
                logEvent('MIXED_CONTENT_RISK', 'Provider HTTP: uma futura página Semyra HTTPS não deve presumir reprodução direta.');
                logEvent('Future HTTPS Semyra risk', 'Produção HTTPS ainda precisa validar se o provider oferece o mesmo recurso por HTTPS.');
            }
        }

        function recordFatal(type, details, stage, evidence) {
            const extra = evidence || {};
            state = applyPlaybackFailure(state, {
                stage: stage || 'unknown',
                mediaErrorCode: extra.mediaErrorCode,
                hlsFatal: extra.hlsFatal,
                hlsErrorType: extra.hlsErrorType,
                hlsErrorDetails: extra.hlsErrorDetails,
                fatalErrorType: safe(type || ''),
                fatalErrorDetails: safe(details || ''),
            });
            logEvent('ERROR fatal', state.fatalErrorType + ' / ' + state.fatalErrorDetails);
            renderReport();
        }

        function attemptPlay() {
            const promise = video.play();
            if (promise && typeof promise.catch === 'function') {
                promise.catch((error) => logEvent('Autoplay não iniciado', error && error.name ? error.name : 'interação necessária'));
            }
        }

        function attachHls(url, kind) {
            if (typeof win.Hls !== 'undefined' && win.Hls.isSupported()) {
                state.playbackMethod = 'hls.js';
                hls = new win.Hls({debug: false});
                hls.on(win.Hls.Events.MEDIA_ATTACHED, () => logEvent('MEDIA_ATTACHED'));
                hls.on(win.Hls.Events.MANIFEST_LOADED, () => {
                    state.hlsManifest = 'success';
                    updateResult('hlsManifest', 'OK');
                    logEvent('MANIFEST_LOADED');
                });
                hls.on(win.Hls.Events.MANIFEST_PARSED, () => {
                    state.hlsManifest = 'success';
                    logEvent('MANIFEST_PARSED');
                    attemptPlay();
                });
                hls.on(win.Hls.Events.LEVEL_LOADED, (_event, data) => {
                    const live = Boolean(data && data.details && data.details.live);
                    state.liveDvr = live && data.details.totalduration > 0 ? 'window observed' : live ? 'live without confirmed DVR' : 'VOD';
                    updateResult('liveDvr', live && data.details.totalduration > 0 ? 'OK' : live ? 'N/A' : 'N/A');
                    logEvent('LEVEL_LOADED', live ? 'LIVE' : 'VOD');
                });
                hls.on(win.Hls.Events.FRAG_LOADED, () => {
                    state.hlsSegments = 'success';
                    updateResult('hlsSegments', 'OK');
                });
                hls.on(win.Hls.Events.AUDIO_TRACKS_UPDATED, (_event, data) => {
                    const count = data && Array.isArray(data.audioTracks) ? data.audioTracks.length : 0;
                    logEvent('AUDIO_TRACKS_UPDATED', String(count));
                });
                hls.on(win.Hls.Events.ERROR, (_event, data) => {
                    const fatal = Boolean(data && data.fatal);
                    const type = safe(data && data.type ? data.type : 'unknown');
                    const details = safe(data && data.details ? data.details : 'unknown');
                    state.hlsFatal = fatal;
                    state.hlsErrorType = type;
                    state.hlsErrorDetails = details;
                    logEvent('ERROR', (fatal ? 'fatal ' : '') + type + ' / ' + details);
                    if (fatal) {
                        const failureStage = state.hlsManifest !== 'success'
                            ? 'hls-manifest'
                            : state.hlsSegments !== 'success' ? 'hls-fragment' : 'decode';
                        if (state.hlsManifest !== 'success') {
                            state.hlsManifest = 'fail';
                            updateResult('hlsManifest', 'FAIL');
                        }
                        if (state.hlsSegments !== 'success') {
                            state.hlsSegments = state.hlsManifest === 'fail' ? 'unknown' : 'fail';
                            updateResult('hlsSegments', state.hlsSegments === 'fail' ? 'FAIL' : 'N/A');
                        }
                        recordFatal(type, details, failureStage, {
                            hlsFatal: true,
                            hlsErrorType: type,
                            hlsErrorDetails: details,
                        });
                    }
                });
                hls.attachMedia(video);
                hls.loadSource(url);
                return;
            }
            if (video.canPlayType('application/vnd.apple.mpegurl')) {
                state.playbackMethod = 'native-video';
                video.src = url;
                logEvent('HLS nativo', 'fallback anunciado pelo navegador');
                attemptPlay();
                return;
            }
            state.playbackMethod = 'none';
            recordFatal('unsupported', 'HLS.js e HLS nativo indisponíveis', 'media-selection');
        }

        function playMedia(url, requestedType, context) {
            const cleanUrl = String(url || '').trim();
            const providedCredentialStatus = context && context.credentialsInUrl;
            const mediaCredentialStatus = ['yes', 'no', 'unknown'].includes(providedCredentialStatus)
                ? providedCredentialStatus
                : providedCredentialStatus === true ? 'yes'
                    : providedCredentialStatus === false ? 'no'
                        : detectUrlCredentials(cleanUrl, secrets);
            state.mediaTested = true;
            state.mediaProtocol = providerProtocol(cleanUrl);
            state.detectedMediaType = detectedMediaType(cleanUrl, requestedType);
            state.credentialsInMediaUrl = mediaCredentialStatus;
            state.credentialsInPlaybackUrl = mediaCredentialStatus;
            state.playbackMethod = 'none';
            state.playbackFailureStage = 'none';
            state.mediaErrorCode = 'none';
            state.hlsManifest = 'not-tested';
            state.hlsSegments = 'not-tested';
            state.playback = 'not-tested';
            state.hlsFatal = 'unknown';
            state.hlsErrorType = 'none';
            state.hlsErrorDetails = 'none';
            state.resolution = 'unknown';
            state.liveDvr = 'unknown';
            state.seekable = 'unknown';
            state.vodPlayback = 'not-tested';
            state.vodSeek = 'not-tested';
            state.vodDuration = 'unknown';
            state.results.hlsManifest = 'N/A';
            state.results.hlsSegments = 'N/A';
            state.results.livePlayback = 'N/A';
            state.results.liveDvr = 'N/A';
            state.results.vodPlayback = 'N/A';
            state.results.vodSeek = 'N/A';
            if (!cleanUrl) {
                logEvent('Entrada inválida', 'Informe uma URL de mídia.');
                recordFatal('input-error', 'URL de mídia ausente', 'media-selection');
                return;
            }
            let parsed;
            try {
                parsed = new URL(cleanUrl);
                if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('unsupported protocol');
            } catch (_error) {
                logEvent('Entrada inválida', 'Use uma URL HTTP ou HTTPS válida.');
                recordFatal('input-error', 'URL de mídia inválida', 'media-selection');
                return;
            }
            destroyPlayer();
            setProviderDiagnostics(cleanUrl);
            currentMediaKind = classifyMediaUrl(cleanUrl, requestedType);
            state.sourceMode = context && context.mode ? context.mode : state.sourceMode;
            state.liveTested = Boolean(context && context.live);
            state.vodTested = Boolean(context && context.vod);
            if (state.liveTested) {
                state.liveFormat = currentMediaKind;
                updateResult('livePlayback', 'PENDING');
            }
            if (state.vodTested) {
                state.vodExtension = context.extension || currentMediaKind;
                updateResult('vodPlayback', 'PENDING');
            }
            updateResult('credentialsExposed', mediaCredentialStatus === 'yes' ? 'YES' : mediaCredentialStatus === 'no' ? 'NO' : 'UNKNOWN');
            state.fatalErrorType = 'none';
            state.fatalErrorDetails = 'none';
            logEvent('Teste iniciado', currentMediaKind);
            if (currentMediaKind === 'hls') {
                attachHls(cleanUrl, context || {});
            } else {
                state.playbackMethod = 'direct-video';
                video.src = cleanUrl;
                attemptPlay();
            }
            renderReport();
        }

        const videoEvents = ['loadedmetadata', 'canplay', 'playing', 'pause', 'waiting', 'stalled', 'ended', 'error', 'durationchange'];
        for (const eventName of videoEvents) {
            video.addEventListener(eventName, () => {
                let detail = '';
                if (eventName === 'error') detail = video.error ? 'MediaError.code=' + video.error.code : 'sem código';
                logEvent(eventName, detail);
                if (eventName === 'playing') {
                    state.playback = 'success';
                    state.playbackFailureStage = 'none';
                    state.fatalErrorType = 'none';
                    state.fatalErrorDetails = 'none';
                    if (currentMediaKind === 'hls' && state.hlsFatal !== true) state.hlsFatal = false;
                    if (state.liveTested) updateResult('livePlayback', 'OK');
                    if (state.vodTested) {
                        state.vodPlayback = 'success';
                        updateResult('vodPlayback', 'OK');
                    }
                }
                if (eventName === 'loadedmetadata') {
                    loadedMetadataForCurrentMedia = true;
                    if (state.vodTested) state.vodDuration = Number.isFinite(video.duration) ? video.duration.toFixed(2) : 'unknown';
                }
                if (eventName === 'error') {
                    const mediaErrorCode = video.error ? video.error.code : null;
                    const failureStage = currentMediaKind === 'hls'
                        ? state.hlsManifest !== 'success' ? 'hls-manifest'
                            : state.hlsSegments !== 'success' ? 'hls-fragment' : 'decode'
                        : loadedMetadataForCurrentMedia ? 'decode' : 'native-load';
                    recordFatal(
                        'browser-media-error',
                        mediaErrorCode == null ? 'unknown' : 'MediaError.code=' + mediaErrorCode,
                        failureStage,
                        {mediaErrorCode},
                    );
                    if (state.liveTested) updateResult('livePlayback', 'FAIL');
                    if (state.vodTested) updateResult('vodPlayback', 'FAIL');
                }
                renderVideoState();
            });
        }
        video.addEventListener('seeked', () => {
            if (state.vodTested) {
                state.vodSeek = 'success';
                updateResult('vodSeek', 'OK');
            }
            logEvent('seeked');
        });

        function xtreamUrl(action, parameters) {
            const endpoint = new URL(xtreamContext.baseUrl + '/player_api.php');
            endpoint.searchParams.set('username', xtreamContext.username);
            endpoint.searchParams.set('password', xtreamContext.password);
            if (action) endpoint.searchParams.set('action', action);
            for (const [key, value] of Object.entries(parameters || {})) {
                endpoint.searchParams.set(key, value);
            }
            return endpoint.toString();
        }

        async function fetchJson(url) {
            const response = await win.fetch(url, {method: 'GET', mode: 'cors', credentials: 'omit', cache: 'no-store'});
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        }

        function xtreamPlaybackConsent() {
            if (xtreamPlaybackWarningAccepted) return true;
            byId('xtream-credential-warning').hidden = false;
            xtreamPlaybackWarningAccepted = win.confirm(
                'Na reprodução direta pelo navegador, as credenciais podem aparecer na URL/rede do próprio navegador. Este laboratório não tenta escondê-las. Continuar?',
            );
            return xtreamPlaybackWarningAccepted;
        }

        function renderCatalog(container, entries, options) {
            container.replaceChildren();
            const visible = entries.slice(0, MAX_CATALOG_ITEMS);
            if (!visible.length) {
                const empty = doc.createElement('p');
                empty.textContent = 'Nenhum item disponível nesta amostra.';
                container.append(empty);
                return;
            }
            for (const entry of visible) {
                const row = doc.createElement('div');
                const label = doc.createElement('span');
                const actions = doc.createElement('div');
                row.className = 'catalog-item';
                actions.className = 'catalog-actions';
                label.textContent = entry.label || 'Item sem nome';
                for (const action of options(entry)) {
                    const button = doc.createElement('button');
                    button.type = 'button';
                    button.textContent = action.label;
                    button.addEventListener('click', action.run);
                    actions.append(button);
                }
                row.append(label, actions);
                container.append(row);
            }
            const count = doc.createElement('p');
            count.textContent = 'Exibindo ' + visible.length + ' de ' + entries.length + ' itens.';
            container.prepend(count);
        }

        byId('xtream-auth').addEventListener('click', async () => {
            try {
                const baseUrl = normalizeBaseUrl(byId('xtream-base-url').value);
                const username = byId('xtream-username').value;
                const password = byId('xtream-password').value;
                if (!username || !password) throw new Error('Preencha username e password somente nesta página local.');
                secrets = [username, password];
                xtreamContext = {baseUrl, username, password, allowedFormats: []};
                state.sourceMode = 'xtream';
                setProviderDiagnostics(baseUrl);
                logEvent('Xtream Auth', 'requisição iniciada');
                const payload = await fetchJson(xtreamUrl(''));
                const userInfo = payload && typeof payload.user_info === 'object' ? payload.user_info : {};
                const serverInfo = payload && typeof payload.server_info === 'object' ? payload.server_info : {};
                const authenticated = String(userInfo.auth) === '1' || userInfo.auth === true;
                const allowed = Array.isArray(userInfo.allowed_output_formats) ? userInfo.allowed_output_formats : [];
                xtreamContext.allowedFormats = allowed;
                state.allowedFormats = allowed;
                state.xtreamAuth = authenticated ? 'success' : 'fail';
                state.apiCatalogCors = 'success';
                state.providerHttpsAdvertised = Object.prototype.hasOwnProperty.call(serverInfo, 'https_port')
                    ? serverInfo.https_port ? 'yes' : 'no'
                    : 'unknown';
                updateResult('xtreamAuth', authenticated ? 'OK' : 'FAIL');
                updateResult('apiCors', 'OK');
                setDefinitionList(byId('xtream-summary'), [
                    ['Auth', authenticated ? 'OK' : 'FAIL'],
                    ['Status', userInfo.status || 'unknown'],
                    ['Trial', userInfo.is_trial == null ? 'unknown' : String(userInfo.is_trial)],
                    ['Conexões ativas', userInfo.active_cons == null ? 'unknown' : String(userInfo.active_cons)],
                    ['Máximo de conexões', userInfo.max_connections == null ? 'unknown' : String(userInfo.max_connections)],
                    ['Formatos permitidos', safeList(allowed)],
                    ['Protocolo do servidor', serverInfo.server_protocol || 'unknown'],
                    ['Porta', serverInfo.port || 'unknown'],
                    ['Porta HTTPS anunciada', serverInfo.https_port || 'unknown'],
                    ['Timezone', serverInfo.timezone || 'unknown'],
                ]);
                byId('xtream-live').disabled = !authenticated;
                byId('xtream-vod').disabled = !authenticated;
                logEvent('Xtream Auth', authenticated ? 'AUTH OK' : 'AUTH FAIL');
                byId('xtream-input-status').textContent = authenticated
                    ? 'Conexão Xtream testada; campos sensíveis limpos. Protocolo: ' + state.providerProtocol.toUpperCase() + '.'
                    : 'Autenticação recusada; campos sensíveis limpos.';
                if (!authenticated) {
                    xtreamContext = null;
                    secrets = [];
                }
            } catch (error) {
                state.xtreamAuth = 'network-fail-or-cors';
                state.apiCatalogCors = 'fail';
                updateResult('xtreamAuth', 'FAIL');
                updateResult('apiCors', 'FAIL');
                setDefinitionList(byId('xtream-summary'), [['Resultado', 'NETWORK FAIL / CORS POSSÍVEL'], ['Detalhe', safe(error.message)]]);
                logEvent('Xtream Auth', 'NETWORK FAIL / CORS POSSÍVEL — ' + safe(error.message));
                byId('xtream-input-status').textContent = 'Teste sem sucesso; campos sensíveis limpos.';
                xtreamContext = null;
                secrets = [];
            }
            byId('xtream-base-url').value = '';
            byId('xtream-username').value = '';
            byId('xtream-password').value = '';
            renderReport();
        });

        function xtreamStreamActions(kind, entry) {
            const isLive = kind === 'live';
            if (isLive) {
                return [
                    {label: 'Testar HLS', run: () => {
                        if (!xtreamPlaybackConsent()) return;
                        playMedia(xtreamContext.baseUrl + '/live/' + encodeURIComponent(xtreamContext.username) + '/' + encodeURIComponent(xtreamContext.password) + '/' + encodeURIComponent(entry.id) + '.m3u8', 'hls', {mode: 'xtream', live: true, credentialsInUrl: true});
                    }},
                    {label: 'Testar TS', run: () => {
                        if (!xtreamPlaybackConsent()) return;
                        playMedia(xtreamContext.baseUrl + '/live/' + encodeURIComponent(xtreamContext.username) + '/' + encodeURIComponent(xtreamContext.password) + '/' + encodeURIComponent(entry.id) + '.ts', 'ts', {mode: 'xtream', live: true, credentialsInUrl: true});
                    }},
                ];
            }
            const extension = /^[a-z0-9]{1,8}$/i.test(entry.extension) ? entry.extension.toLowerCase() : 'mp4';
            return [{label: 'Testar VOD', run: () => {
                if (!xtreamPlaybackConsent()) return;
                playMedia(xtreamContext.baseUrl + '/movie/' + encodeURIComponent(xtreamContext.username) + '/' + encodeURIComponent(xtreamContext.password) + '/' + encodeURIComponent(entry.id) + '.' + extension, 'auto', {mode: 'xtream', vod: true, extension, credentialsInUrl: true});
            }}];
        }

        async function loadXtreamStreams(kind, categoryId) {
            const isLive = kind === 'live';
            const streamsAction = isLive ? 'get_live_streams' : 'get_vod_streams';
            try {
                const parameters = categoryId ? {category_id: categoryId} : {};
                const streams = await fetchJson(xtreamUrl(streamsAction, parameters));
                state.apiCatalogCors = 'success';
                updateResult('apiCors', 'OK');
                const entries = (Array.isArray(streams) ? streams : []).map((stream) => ({
                    id: String(stream.stream_id || ''),
                    extension: String(stream.container_extension || ''),
                    label: String(stream.name || 'Item sem nome'),
                })).filter((stream) => stream.id);
                renderCatalog(byId('xtream-catalog'), entries, (entry) => xtreamStreamActions(kind, entry));
                logEvent('Streams Xtream', kind + ' carregado por categoria; renderização limitada a ' + MAX_CATALOG_ITEMS);
            } catch (error) {
                state.apiCatalogCors = 'fail';
                updateResult('apiCors', 'FAIL');
                logEvent('Catálogo Xtream', 'NETWORK FAIL / CORS POSSÍVEL — ' + safe(error.message));
            }
            renderReport();
        }

        async function loadXtreamCatalog(kind) {
            if (!xtreamContext) return;
            const categoriesAction = kind === 'live' ? 'get_live_categories' : 'get_vod_categories';
            try {
                const categories = await fetchJson(xtreamUrl(categoriesAction));
                state.apiCatalogCors = 'success';
                updateResult('apiCors', 'OK');
                const entries = (Array.isArray(categories) ? categories : []).map((category) => ({
                    id: String(category.category_id || ''),
                    label: String(category.category_name || 'Categoria sem nome'),
                })).filter((category) => category.id);
                if (!entries.length) {
                    await loadXtreamStreams(kind, '');
                    return;
                }
                renderCatalog(byId('xtream-catalog'), entries, (entry) => [{
                    label: 'Carregar categoria',
                    run: () => loadXtreamStreams(kind, entry.id),
                }]);
                logEvent('Categorias Xtream', kind + ' carregadas; escolha uma antes de buscar streams');
            } catch (error) {
                state.apiCatalogCors = 'fail';
                updateResult('apiCors', 'FAIL');
                logEvent('Categorias Xtream', 'NETWORK FAIL / CORS POSSÍVEL — ' + safe(error.message));
            }
            renderReport();
        }

        byId('xtream-live').addEventListener('click', () => loadXtreamCatalog('live'));
        byId('xtream-vod').addEventListener('click', () => loadXtreamCatalog('vod'));

        function renderM3u(source) {
            const entries = parseM3u(source);
            state.sourceMode = 'm3u';
            state.m3uParsedItems = entries.length;
            renderCatalog(byId('m3u-catalog'), entries.map((entry) => ({
                label: entry.name + (entry.groupTitle ? ' · ' + entry.groupTitle : ''),
                url: entry.url,
            })), (entry) => [{label: 'Testar mídia', run: () => playMedia(entry.url, 'auto', {
                mode: 'm3u',
                credentialsInUrl: detectUrlCredentials(entry.url, secrets),
            })}]);
            logEvent('M3U analisado', entries.length + ' itens; renderização limitada a ' + MAX_CATALOG_ITEMS);
            renderReport();
        }

        byId('m3u-fetch').addEventListener('click', async () => {
            const url = byId('m3u-url').value.trim();
            const credentialStatus = detectUrlCredentials(url, secrets);
            state.credentialsInPlaylistUrl = credentialStatus;
            state.remoteM3uProtocol = providerProtocol(url);
            state.m3uPlaylistFetch = 'not-tested';
            try {
                setProviderDiagnostics(url);
                const response = await win.fetch(url, {mode: 'cors', credentials: 'omit', cache: 'no-store'});
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const playlistText = await response.text();
                state.m3uPlaylistFetch = 'success';
                byId('m3u-url').value = '';
                byId('m3u-input-status').textContent = 'Playlist remota carregada. Protocolo: ' + state.remoteM3uProtocol.toUpperCase() + '.';
                renderM3u(playlistText);
            } catch (error) {
                state.m3uPlaylistFetch = 'fail';
                logEvent('M3U remoto', 'NETWORK FAIL / CORS POSSÍVEL — ' + safe(error.message));
                byId('m3u-input-status').textContent = 'Falha ao carregar playlist remota.';
                if (credentialStatus === 'yes') byId('m3u-url').value = '';
            }
            renderReport();
        });
        byId('m3u-file').addEventListener('change', () => {
            const file = byId('m3u-file').files && byId('m3u-file').files[0];
            if (!file) return;
            const reader = new win.FileReader();
            reader.addEventListener('load', () => {
                state.m3uPlaylistFetch = 'not-tested';
                state.remoteM3uProtocol = 'n/a';
                state.credentialsInPlaylistUrl = 'unknown';
                byId('m3u-input-status').textContent = 'Playlist local carregada na memória.';
                renderM3u(String(reader.result || ''));
            });
            reader.addEventListener('error', () => logEvent('Arquivo M3U', 'falha de leitura local'));
            reader.readAsText(file);
        });
        byId('m3u-parse').addEventListener('click', () => {
            state.m3uPlaylistFetch = 'not-tested';
            state.remoteM3uProtocol = 'n/a';
            state.credentialsInPlaylistUrl = 'unknown';
            byId('m3u-input-status').textContent = 'Texto M3U analisado somente na memória.';
            const playlistText = byId('m3u-text').value;
            byId('m3u-text').value = '';
            renderM3u(playlistText);
        });

        byId('direct-play').addEventListener('click', () => {
            state.sourceMode = 'direct';
            const url = byId('direct-url').value;
            const credentialStatus = detectUrlCredentials(url, secrets);
            if (credentialStatus === 'yes') byId('direct-url').value = '';
            playMedia(url, byId('direct-type').value, {mode: 'direct', credentialsInUrl: credentialStatus});
        });
        byId('public-hls-test').addEventListener('click', () => {
            byId('direct-url').value = PUBLIC_HLS_TEST_URL;
            byId('direct-type').value = 'hls';
            state.sourceMode = 'direct';
            playMedia(PUBLIC_HLS_TEST_URL, 'hls', {mode: 'direct', vod: true, extension: 'm3u8', credentialsInUrl: 'no'});
        });

        for (const radio of doc.querySelectorAll('input[name="source-mode"]')) {
            radio.addEventListener('change', () => {
                state.sourceMode = radio.value;
                for (const mode of ['xtream', 'm3u', 'direct', 'mpegts']) {
                    byId('mode-' + mode).hidden = mode !== radio.value;
                }
                renderReport();
            });
        }

        byId('copy-report').addEventListener('click', async () => {
            const report = buildSanitizedReport(state, capabilities, secrets);
            byId('sanitized-report').value = report;
            try {
                await win.navigator.clipboard.writeText(report);
                byId('copy-status').textContent = 'Relatório sanitizado copiado.';
            } catch (_error) {
                byId('sanitized-report').focus();
                byId('sanitized-report').select();
                byId('copy-status').textContent = 'Clipboard indisponível. O relatório foi selecionado para cópia manual.';
            }
        });

        byId('clear-data').addEventListener('click', () => {
            destroyPlayer();
            for (const id of ['xtream-base-url', 'xtream-username', 'xtream-password', 'm3u-url', 'm3u-text', 'direct-url']) byId(id).value = '';
            byId('m3u-file').value = '';
            byId('direct-type').value = 'auto';
            byId('xtream-summary').replaceChildren();
            byId('xtream-catalog').replaceChildren();
            byId('m3u-catalog').replaceChildren();
            byId('event-log').replaceChildren();
            byId('video-state').replaceChildren();
            byId('copy-status').textContent = '';
            byId('xtream-input-status').textContent = '';
            byId('m3u-input-status').textContent = '';
            byId('xtream-credential-warning').hidden = true;
            byId('xtream-live').disabled = true;
            byId('xtream-vod').disabled = true;
            secrets = [];
            xtreamContext = null;
            xtreamPlaybackWarningAccepted = false;
            state = initialReportState();
            renderReport();
        });

        renderVideoState();
        renderReport();
    }

    return {
        PUBLIC_HLS_TEST_URL,
        redactSensitive,
        detectUrlCredentials,
        parseM3u,
        classifyMediaUrl,
        detectedMediaType,
        detectMixedContent,
        providerProtocol,
        futureHttpsRisk,
        normalizeBaseUrl,
        initialReportState,
        applyPlaybackFailure,
        buildSanitizedReport,
        initialize,
    };
}));
