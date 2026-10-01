(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.MpegtsSpike = api;

    if (typeof document !== 'undefined') {
        document.addEventListener('DOMContentLoaded', () => api.initialize(document, root));
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const CANDIDATE_LABELS = ['LIVE_H264_1', 'LIVE_H264_2', 'LIVE_H265_1', 'VOD_1'];
    const FEATURE_KEYS = [
        'msePlayback',
        'mseLivePlayback',
        'mseH265Playback',
        'networkStreamIO',
        'networkLoaderName',
        'nativeMP4H264Playback',
        'nativeMP4H265Playback',
    ];

    function isValidCandidateId(value) {
        return /^[a-f0-9]{32}$/.test(String(value || ''));
    }

    function sanitizeDiagnostic(value) {
        return String(value == null ? '' : value)
            .replace(/https?:\/\/[^\s]+/gi, '[redacted-url]')
            .replace(/([?&](?:username|user|password|pass|token|auth|key)=)[^&#\s]*/gi, '$1***')
            .replace(/\b[a-f0-9]{32}\b/gi, '[candidate-id]')
            .replace(/[\r\n\x00-\x1F\x7F]+/g, ' ')
            .slice(0, 180);
    }

    function normalizeFeatures(source) {
        const features = source && typeof source === 'object' ? source : {};
        const normalized = {};
        for (const key of FEATURE_KEYS) {
            if (key === 'networkLoaderName') {
                const loader = String(features[key] || 'unknown');
                normalized[key] = /^[a-z0-9-]{1,60}$/i.test(loader) ? loader : 'unknown';
            } else {
                normalized[key] = Boolean(features[key]);
            }
        }
        return normalized;
    }

    function initialCandidateResult(label) {
        return {
            label,
            payload: 'not-tested',
            playback: 'not-tested',
            videoCodec: 'unknown',
            audioCodec: 'unknown',
            resolution: 'unknown',
            audio: 'user-confirmation-required',
            stability: 'not-tested',
            bufferLatency: 'unknown',
            pauseResume: 'not-tested',
            reconnect: 'not-tested',
            seekDvr: 'not-tested',
            duration: 'unknown',
            seek30: 'not-tested',
            randomSeek: 'not-tested',
            rangeSupport: 'unknown',
            cors: 'not-tested',
            error: 'none',
        };
    }

    function initialState() {
        return {
            activeLabel: 'none',
            results: Object.fromEntries(CANDIDATE_LABELS.map((label) => [label, initialCandidateResult(label)])),
        };
    }

    function resetState() {
        return initialState();
    }

    function value(source) {
        if (typeof source === 'boolean') return source ? 'yes' : 'no';
        if (source === null || source === undefined || source === '') return 'unknown';
        return sanitizeDiagnostic(source);
    }

    function candidateReport(result, isH265, isVod) {
        const lines = [
            result.label,
            'Payload: ' + value(result.payload),
        ];
        if (isH265) lines.push('Feature supported: ' + value(result.featureSupported));
        lines.push(
            'Playback: ' + value(result.playback),
            'Video codec: ' + value(result.videoCodec),
            'Audio codec: ' + value(result.audioCodec),
            'Resolution: ' + value(result.resolution),
            'Audio: ' + value(result.audio),
        );
        if (isVod) {
            lines.push(
                'Duration: ' + value(result.duration),
                'Pause/resume: ' + value(result.pauseResume),
                'Seek +30: ' + value(result.seek30),
                'Random seek: ' + value(result.randomSeek),
                'Range support: ' + value(result.rangeSupport),
            );
        } else {
            lines.push(
                'Stability: ' + value(result.stability),
                'Buffer latency: ' + value(result.bufferLatency),
                'Pause/resume: ' + value(result.pauseResume),
                'Reconnect: ' + value(result.reconnect),
                'Seek/DVR: ' + value(result.seekDvr),
            );
        }
        lines.push('CORS: ' + value(result.cors), 'Error: ' + value(result.error), '');
        return lines;
    }

    function buildReport(state, featureSource, browser) {
        const current = state && state.results ? state : initialState();
        const features = normalizeFeatures(featureSource);
        const liveResults = ['LIVE_H264_1', 'LIVE_H264_2'].map((label) => current.results[label]);
        const livePossible = liveResults.some((result) => result.playback === 'success');
        const liveFailed = liveResults.some((result) => result.playback === 'fail');
        const vod = current.results.VOD_1;
        const vodConclusion = vod.playback === 'success'
            ? ((vod.seek30 === 'success' || vod.randomSeek === 'success') ? 'possible' : 'limited')
            : vod.playback === 'fail' ? 'fail' : 'inconclusive';
        const lines = [
            'MPEGTS.JS PLAYBACK REPORT',
            '',
            'Library: mpegts.js 1.8.2',
            'Browser: ' + value(browser),
            'Test mode: LOCAL HTTP TEST',
            '',
            'Features:',
            ...FEATURE_KEYS.map((key) => key + ': ' + value(features[key])),
            'isSupported: ' + value(features.msePlayback),
            '',
            ...candidateReport(current.results.LIVE_H264_1, false, false),
            ...candidateReport(current.results.LIVE_H264_2, false, false),
            ...candidateReport(Object.assign({}, current.results.LIVE_H265_1, {
                featureSupported: features.mseH265Playback,
            }), true, false),
            ...candidateReport(vod, false, true),
            'LOCAL CONCLUSION',
            'Continuous MPEG-TS Live: ' + (livePossible ? 'possible' : liveFailed ? 'fail' : 'inconclusive'),
            'Continuous MPEG-TS VOD: ' + vodConclusion,
            '',
            'PRODUCTION CONCLUSION',
            'Direct browser media: HTTP only',
            'HTTPS provider: unavailable',
            'Mixed content: blocking risk',
            'Credentials exposure in browser network: possible',
            '',
            'No URLs/secrets.',
        ];
        return lines.map(sanitizeDiagnostic).join('\n');
    }

    function initialize(doc, win) {
        const byId = (id) => doc.getElementById(id);
        if (!byId('mpegts-candidate')) return;

        const video = byId('video');
        const library = win.mpegts;
        const rawFeatures = library && typeof library.getFeatureList === 'function'
            ? library.getFeatureList()
            : {};
        const features = normalizeFeatures(rawFeatures);
        let state = initialState();
        let player = null;
        let currentCandidate = null;
        let stabilityTimer = null;
        let reconnectStartedAt = null;
        let pendingSeek = null;
        let playStartedAt = null;
        let initialBufferEnd = null;

        if (library && library.LoggingControl) {
            library.LoggingControl.enableAll = false;
        }

        function activeResult() {
            return currentCandidate ? state.results[currentCandidate.label] : null;
        }

        function logEvent(name, detail) {
            const item = doc.createElement('li');
            item.textContent = new Date().toLocaleTimeString() + ' · ' + sanitizeDiagnostic(name)
                + (detail ? ' · ' + sanitizeDiagnostic(detail) : '');
            byId('mpegts-event-log').prepend(item);
            while (byId('mpegts-event-log').children.length > 30) {
                byId('mpegts-event-log').lastElementChild.remove();
            }
        }

        function renderFeatures() {
            const rows = [
                ['mpegts.version', library ? library.version : 'unavailable'],
                ['mpegts.isSupported()', library && typeof library.isSupported === 'function' ? library.isSupported() : false],
                ...FEATURE_KEYS.map((key) => [key, features[key]]),
            ];
            byId('mpegts-features').replaceChildren();
            for (const [label, rawValue] of rows) {
                const dt = doc.createElement('dt');
                const dd = doc.createElement('dd');
                dt.textContent = label;
                dd.textContent = value(rawValue);
                byId('mpegts-features').append(dt, dd);
            }
        }

        function renderReport() {
            byId('mpegts-report').value = buildReport(state, features, win.navigator.userAgent);
            const result = activeResult();
            byId('mpegts-active-state').textContent = result
                ? result.label + ': ' + result.playback + ' · stability ' + result.stability
                : 'Nenhum candidato ativo.';
        }

        function updateBufferMetrics() {
            const result = activeResult();
            if (!result) return;
            if (video.buffered.length > 0) {
                const end = video.buffered.end(video.buffered.length - 1);
                if (initialBufferEnd === null) initialBufferEnd = end;
                if (currentCandidate.isLive) {
                    result.bufferLatency = Math.max(0, Math.round((end - video.currentTime) * 1000)) + ' ms';
                }
            }
            if (currentCandidate.isLive && !['running', 'success'].includes(result.seekDvr)) {
                result.seekDvr = video.seekable.length > 0
                    ? 'local-buffer-window; provider-DVR-unconfirmed'
                    : 'unavailable';
            }
            if (!currentCandidate.isLive) {
                result.duration = Number.isFinite(video.duration) ? video.duration.toFixed(2) + ' s' : 'unknown';
            }
        }

        function clearStabilityTimer() {
            if (stabilityTimer !== null) {
                win.clearInterval(stabilityTimer);
                stabilityTimer = null;
            }
        }

        function startStabilityTimer() {
            clearStabilityTimer();
            playStartedAt = Date.now();
            initialBufferEnd = video.buffered.length > 0 ? video.buffered.end(video.buffered.length - 1) : null;
            stabilityTimer = win.setInterval(() => {
                const result = activeResult();
                if (!result || result.playback === 'fail' || video.paused) return;
                updateBufferMetrics();
                const requiredSeconds = currentCandidate.label === 'LIVE_H264_1' ? 60 : 30;
                if ((Date.now() - playStartedAt) / 1000 >= requiredSeconds) {
                    const finalBufferEnd = video.buffered.length > 0 ? video.buffered.end(video.buffered.length - 1) : null;
                    result.stability = finalBufferEnd !== null
                        && (initialBufferEnd === null || finalBufferEnd > initialBufferEnd)
                        ? 'success'
                        : 'fail';
                    clearStabilityTimer();
                    renderReport();
                }
            }, 1000);
        }

        function destroyPlayer(clearCandidate) {
            clearStabilityTimer();
            playStartedAt = null;
            initialBufferEnd = null;
            if (player) {
                try { player.pause(); } catch (_error) {}
                try { player.unload(); } catch (_error) {}
                try { player.detachMediaElement(); } catch (_error) {}
                try { player.destroy(); } catch (_error) {}
                player = null;
            }
            video.pause();
            video.removeAttribute('src');
            video.load();
            if (clearCandidate) currentCandidate = null;
        }

        async function probeRange(url) {
            try {
                const response = await win.fetch(url, {
                    method: 'GET',
                    headers: {Range: 'bytes=0-0'},
                    mode: 'cors',
                    cache: 'no-store',
                    credentials: 'omit',
                });
                const supported = response.status === 206
                    || response.headers.get('accept-ranges') === 'bytes'
                    || Boolean(response.headers.get('content-range'));
                if (response.body && typeof response.body.cancel === 'function') await response.body.cancel();
                return supported ? 'yes' : 'no';
            } catch (_error) {
                return 'no';
            }
        }

        function attachPlayerEvents(result) {
            player.on(library.Events.ERROR, (errorType, errorDetail) => {
                result.playback = 'fail';
                result.error = sanitizeDiagnostic(String(errorType || 'error') + '/' + String(errorDetail || 'unknown'));
                logEvent('ERROR', result.error);
                renderReport();
            });
            player.on(library.Events.LOADING_COMPLETE, () => logEvent('LOADING_COMPLETE'));
            player.on(library.Events.RECOVERED_EARLY_EOF, () => logEvent('RECOVERED_EARLY_EOF'));
            player.on(library.Events.MEDIA_INFO, (info) => {
                result.videoCodec = sanitizeDiagnostic(info.videoCodec || 'unknown');
                result.audioCodec = sanitizeDiagnostic(info.audioCodec || 'unknown');
                result.resolution = info.width && info.height ? info.width + 'x' + info.height : 'unknown';
                result.audio = info.hasAudio === false ? 'not-present' : 'user-confirmation-required';
                result.payload = 'mpeg-ts';
                result.cors = 'success-observed';
                logEvent('MEDIA_INFO', result.videoCodec + ' · ' + result.audioCodec + ' · ' + result.resolution);
                renderReport();
            });
            player.on(library.Events.STATISTICS_INFO, (statistics) => {
                const speed = Number(statistics.speed || 0);
                const decoded = Number(statistics.decodedFrames || 0);
                const dropped = Number(statistics.droppedFrames || 0);
                updateBufferMetrics();
                byId('mpegts-statistics').textContent = 'loader=' + sanitizeDiagnostic(statistics.loaderType || features.networkLoaderName)
                    + ' · speed=' + (Number.isFinite(speed) ? speed.toFixed(1) : 'unknown')
                    + ' · decoded=' + (Number.isFinite(decoded) ? decoded : 'unknown')
                    + ' · dropped=' + (Number.isFinite(dropped) ? dropped : 'unknown');
                renderReport();
            });
        }

        async function createPlayer(candidate, isReconnect) {
            const result = state.results[candidate.label];
            if (!library || typeof library.createPlayer !== 'function' || !features.msePlayback) {
                result.playback = 'fail';
                result.error = 'mpegts.js/MSE unsupported';
                renderReport();
                return;
            }
            if (candidate.h265Hint && !features.mseH265Playback) {
                result.playback = 'not-tested';
                result.error = 'HEVC browser support unavailable';
                renderReport();
                return;
            }
            if (isReconnect) reconnectStartedAt = Date.now();
            player = library.createPlayer({type: 'mpegts', isLive: candidate.isLive, url: candidate.url}, {enableWorker: false});
            attachPlayerEvents(result);
            player.attachMediaElement(video);
            player.load();
            try {
                await video.play();
            } catch (_error) {
                if (result.playback !== 'fail' && result.error === 'none') {
                    result.error = 'play requires user interaction or failed';
                }
                logEvent('PLAY_REQUEST', 'play requires user interaction or failed');
            }
            renderReport();
        }

        async function loadCandidates() {
            const select = byId('mpegts-candidate');
            select.replaceChildren();
            try {
                const response = await win.fetch('candidate.php?action=list', {cache: 'no-store', credentials: 'same-origin'});
                const payload = await response.json();
                const candidates = Array.isArray(payload.candidates) ? payload.candidates : [];
                for (const candidate of candidates) {
                    if (!isValidCandidateId(candidate.id) || !CANDIDATE_LABELS.includes(candidate.label)) continue;
                    const option = doc.createElement('option');
                    option.value = candidate.id;
                    option.textContent = candidate.label;
                    option.dataset.label = candidate.label;
                    select.append(option);
                }
                byId('mpegts-status').textContent = select.options.length
                    ? select.options.length + ' candidatos locais disponíveis.'
                    : 'Nenhum candidato confirmado. Execute o analisador local.';
            } catch (_error) {
                byId('mpegts-status').textContent = 'Mapa local indisponível. Execute o analisador e use o servidor PHP local.';
            }
        }

        async function fetchCandidate(candidateId) {
            if (!isValidCandidateId(candidateId)) throw new Error('invalid-candidate-id');
            const response = await win.fetch('candidate.php?id=' + encodeURIComponent(candidateId), {
                cache: 'no-store', credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('candidate-unavailable');
            const candidate = await response.json();
            if (!CANDIDATE_LABELS.includes(candidate.label) || candidate.payload !== 'mpeg-ts') {
                throw new Error('candidate-invalid');
            }
            const parsedUrl = new URL(candidate.url);
            if (!['http:', 'https:'].includes(parsedUrl.protocol)) throw new Error('candidate-invalid');
            return {
                id: candidateId,
                label: candidate.label,
                url: candidate.url,
                isLive: Boolean(candidate.isLive),
                h265Hint: Boolean(candidate.h265Hint),
            };
        }

        byId('mpegts-refresh').addEventListener('click', loadCandidates);
        byId('mpegts-play').addEventListener('click', async () => {
            destroyPlayer(true);
            try {
                const candidate = await fetchCandidate(byId('mpegts-candidate').value);
                currentCandidate = candidate;
                state.activeLabel = candidate.label;
                state.results[candidate.label] = initialCandidateResult(candidate.label);
                const result = activeResult();
                result.payload = 'mpeg-ts';
                result.cors = 'declared-by-probe';
                if (!candidate.isLive) result.rangeSupport = await probeRange(candidate.url);
                await createPlayer(candidate, false);
            } catch (error) {
                byId('mpegts-status').textContent = sanitizeDiagnostic(error.message || 'candidate-load-failed');
            }
            renderReport();
        });

        byId('mpegts-pause-resume').addEventListener('click', () => {
            const result = activeResult();
            if (!result || video.paused) return;
            const before = video.currentTime;
            video.pause();
            win.setTimeout(async () => {
                try {
                    await video.play();
                    const delta = video.currentTime - before;
                    result.pauseResume = currentCandidate.isLive && delta > 1
                        ? 'success: jumped-forward'
                        : 'success: resumed-near-paused-point';
                } catch (_error) {
                    result.pauseResume = 'fail';
                }
                renderReport();
            }, 5000);
        });

        byId('mpegts-reconnect').addEventListener('click', async () => {
            const candidate = currentCandidate;
            const result = activeResult();
            if (!candidate || !result) return;
            result.reconnect = 'running';
            destroyPlayer(false);
            await createPlayer(candidate, true);
        });

        function requestSeek(kind) {
            const result = activeResult();
            if (!result || video.seekable.length === 0) {
                if (result) result[currentCandidate && currentCandidate.isLive ? 'seekDvr' : kind] = 'unavailable';
                renderReport();
                return;
            }
            const start = video.seekable.start(0);
            const end = video.seekable.end(video.seekable.length - 1);
            if (currentCandidate.isLive) {
                const liveTarget = Math.max(start, end - 5);
                if (!Number.isFinite(liveTarget) || liveTarget <= start) {
                    result.seekDvr = 'unavailable';
                    renderReport();
                    return;
                }
                pendingSeek = 'seekDvr';
                result.seekDvr = 'running';
                video.currentTime = liveTarget;
                renderReport();
                return;
            }
            let target;
            if (kind === 'seek30') {
                target = video.currentTime + 30;
            } else {
                target = Number.isFinite(video.duration) ? video.duration / 2 : Number.NaN;
            }
            if (!Number.isFinite(target) || target <= start || target >= end) {
                result[kind] = 'unavailable';
                renderReport();
                return;
            }
            pendingSeek = kind;
            result[kind] = 'running';
            video.currentTime = target;
            renderReport();
        }

        byId('mpegts-seek-30').addEventListener('click', () => requestSeek('seek30'));
        byId('mpegts-seek-random').addEventListener('click', () => requestSeek('randomSeek'));

        for (const eventName of ['loadedmetadata', 'canplay', 'playing', 'pause', 'waiting', 'stalled', 'error']) {
            video.addEventListener(eventName, () => {
                if (!currentCandidate) return;
                const result = activeResult();
                updateBufferMetrics();
                if (eventName === 'playing') {
                    result.playback = 'success';
                    result.resolution = video.videoWidth && video.videoHeight
                        ? video.videoWidth + 'x' + video.videoHeight
                        : result.resolution;
                    if (!playStartedAt) startStabilityTimer();
                    if (reconnectStartedAt !== null) {
                        result.reconnect = 'success: ' + ((Date.now() - reconnectStartedAt) / 1000).toFixed(1) + ' s';
                        reconnectStartedAt = null;
                    }
                } else if (eventName === 'error') {
                    result.playback = 'fail';
                    result.error = 'MediaError.code=' + (video.error ? video.error.code : 'unknown');
                }
                logEvent(eventName, 'readyState=' + video.readyState + ' networkState=' + video.networkState);
                renderReport();
            });
        }

        video.addEventListener('seeked', () => {
            const result = activeResult();
            if (!result || !pendingSeek) return;
            result[pendingSeek] = pendingSeek === 'seekDvr'
                ? 'success: local-buffer-only; provider-DVR-unconfirmed'
                : 'success';
            pendingSeek = null;
            renderReport();
        });

        byId('clear-data').addEventListener('click', () => {
            destroyPlayer(true);
            state = resetState();
            playStartedAt = null;
            reconnectStartedAt = null;
            pendingSeek = null;
            byId('mpegts-event-log').replaceChildren();
            byId('mpegts-statistics').textContent = '';
            renderReport();
        });

        renderFeatures();
        renderReport();
        loadCandidates();
    }

    return {
        CANDIDATE_LABELS,
        FEATURE_KEYS,
        isValidCandidateId,
        sanitizeDiagnostic,
        normalizeFeatures,
        initialCandidateResult,
        initialState,
        resetState,
        buildReport,
        initialize,
    };
}));
