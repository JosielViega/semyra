'use strict';

(() => {
    const presenceContainer = document.querySelector('[data-room-presence]');
    if (!presenceContainer) {
        return;
    }

    const endpoint = presenceContainer.dataset.presenceUrl ?? '';
    const csrfToken = presenceContainer.dataset.csrfToken ?? '';
    const participantList = document.getElementById('room-participant-list');
    const participantCount = document.getElementById('room-participant-count');
    const statusElement = document.getElementById('room-presence-status');
    let requestInProgress = false;
    let stopped = false;
    let timeoutId = null;
    let latestTelemetry = null;
    let latestTransmission = null;
    let lastLiveEdgeObservationAt = 0;
    let hasActiveTransmission = false;
    const liveEdgeObservationIntervalMs = 5000;

    if (!endpoint || !csrfToken || !participantList || !participantCount || !statusElement) {
        return;
    }

    const updateStatus = (message, isError = false) => {
        statusElement.textContent = message;
        statusElement.classList.toggle('room-presence-status-error', isError);
    };

    const renderParticipants = (participants) => {
        const fragment = document.createDocumentFragment();

        participants.forEach((participant) => {
            const item = document.createElement('li');
            const name = document.createElement('span');
            name.textContent = participant.name;
            item.append(name);

            if (participant.is_you === true) {
                const marker = document.createElement('strong');
                marker.textContent = ' (você)';
                item.append(marker);
            }

            fragment.append(item);
        });

        participantList.replaceChildren(fragment);
        participantCount.textContent = String(participants.length);
    };

    const normalizeTransmission = (transmission) => {
        if (transmission === null) {
            return null;
        }
        if (transmission?.source !== 'youtube'
            || typeof transmission.youtube_video_id !== 'string'
            || !/^[A-Za-z0-9_-]{11}$/.test(transmission.youtube_video_id)
            || !Number.isSafeInteger(transmission.revision)
            || transmission.revision < 1
            || typeof transmission.owner_name !== 'string'
            || typeof transmission.is_owner !== 'boolean'
            || !['unknown', 'vod', 'live'].includes(transmission.media_mode)
            || !['playing', 'paused'].includes(transmission.playback?.state)
            || !Number.isSafeInteger(transmission.playback?.position_ms)
            || transmission.playback.position_ms < 0
            || !Number.isSafeInteger(transmission.playback?.revision)
            || transmission.playback.revision < 1
            || typeof transmission.playback?.at_live_edge !== 'boolean'
            || (transmission.playback.live_edge_position_ms !== null
                && (!Number.isSafeInteger(transmission.playback.live_edge_position_ms)
                    || transmission.playback.live_edge_position_ms < 0))) {
            return null;
        }

        return {
            source: transmission.source,
            videoId: transmission.youtube_video_id,
            revision: transmission.revision,
            ownerName: transmission.owner_name,
            isOwner: transmission.is_owner,
            mediaMode: transmission.media_mode,
            playback: {
                state: transmission.playback.state,
                positionMs: transmission.playback.position_ms,
                revision: transmission.playback.revision,
                atLiveEdge: transmission.playback.at_live_edge,
                liveEdgePositionMs: transmission.playback.live_edge_position_ms,
            },
        };
    };

    const scheduleRefresh = () => {
        if (stopped) {
            return;
        }
        if (timeoutId !== null) {
            window.clearTimeout(timeoutId);
        }
        timeoutId = window.setTimeout(refreshPresence, hasActiveTransmission ? 1000 : 5000);
    };

    document.addEventListener('semyra:player-telemetry', (event) => {
        const telemetry = event.detail;
        if (Number.isInteger(telemetry?.state)
            && Number.isSafeInteger(telemetry?.positionMs)
            && Number.isSafeInteger(telemetry?.durationMs)) {
            latestTelemetry = telemetry;
        }
    });

    const refreshPresence = async () => {
        if (requestInProgress || stopped) {
            return;
        }

        requestInProgress = true;

        try {
            latestTelemetry = null;
            document.dispatchEvent(new CustomEvent('semyra:player-telemetry-request'));
            const body = new URLSearchParams({ _token: csrfToken });
            if (latestTelemetry !== null) {
                body.set('player_state', String(latestTelemetry.state));
                body.set('player_position_ms', String(latestTelemetry.positionMs));
                body.set('player_duration_ms', String(latestTelemetry.durationMs));

                const now = Date.now();
                if (latestTransmission?.isOwner === true
                    && latestTransmission.mediaMode === 'live'
                    && latestTransmission.playback.atLiveEdge === true
                    && latestTransmission.playback.state === 'playing'
                    && latestTelemetry.state === 1
                    && now - lastLiveEdgeObservationAt >= liveEdgeObservationIntervalMs) {
                    body.set('live_edge_position_ms', String(latestTelemetry.positionMs));
                    body.set('live_edge_transmission_revision', String(latestTransmission.revision));
                    body.set('live_edge_playback_revision', String(latestTransmission.playback.revision));
                    lastLiveEdgeObservationAt = now;
                }
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                body,
                credentials: 'same-origin',
            });
            const payload = await response.json();

            if (response.status === 403 && payload.error === 'join_required') {
                stopped = true;
                if (timeoutId !== null) {
                    window.clearTimeout(timeoutId);
                }
                updateStatus('Entre novamente na sala para atualizar sua presença.', true);
                return;
            }

            if (response.status === 422
                && ['invalid_telemetry', 'invalid_live_edge_observation'].includes(payload.error)) {
                updateStatus('Não foi possível registrar os dados do player agora. Tentaremos novamente.', true);
                return;
            }

            if (!response.ok || !Array.isArray(payload.participants)) {
                throw new Error('Invalid presence response.');
            }

            renderParticipants(payload.participants);
            const transmission = normalizeTransmission(payload.transmission ?? null);
            latestTransmission = transmission;
            hasActiveTransmission = transmission !== null;
            document.dispatchEvent(new CustomEvent('semyra:presence-updated', {
                detail: {
                    participants: payload.participants,
                    transmission,
                },
            }));
            updateStatus('Participantes atualizados.');
        } catch {
            updateStatus('Não foi possível atualizar a lista agora. Tentaremos novamente.', true);
        } finally {
            requestInProgress = false;
            scheduleRefresh();
        }
    };

    refreshPresence();

    document.addEventListener('semyra:presence-refresh-request', () => {
        if (timeoutId !== null) {
            window.clearTimeout(timeoutId);
        }
        refreshPresence();
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            if (timeoutId !== null) {
                window.clearTimeout(timeoutId);
            }
            refreshPresence();
        }
    });
})();
