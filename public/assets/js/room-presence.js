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
    let intervalId = null;
    let latestTelemetry = null;

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
                if (intervalId !== null) {
                    window.clearInterval(intervalId);
                }
                updateStatus('Entre novamente na sala para atualizar sua presença.', true);
                return;
            }

            if (response.status === 422 && payload.error === 'invalid_telemetry') {
                updateStatus('Não foi possível registrar os dados do player agora. Tentaremos novamente.', true);
                return;
            }

            if (!response.ok || !Array.isArray(payload.participants)) {
                throw new Error('Invalid presence response.');
            }

            renderParticipants(payload.participants);
            document.dispatchEvent(new CustomEvent('semyra:presence-updated', {
                detail: { participants: payload.participants },
            }));
            updateStatus('Participantes atualizados.');
        } catch {
            updateStatus('Não foi possível atualizar a lista agora. Tentaremos novamente.', true);
        } finally {
            requestInProgress = false;
        }
    };

    refreshPresence();
    intervalId = window.setInterval(refreshPresence, 5000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            refreshPresence();
        }
    });
})();
