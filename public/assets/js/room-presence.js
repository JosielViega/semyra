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

    const refreshPresence = async () => {
        if (requestInProgress || stopped) {
            return;
        }

        requestInProgress = true;

        try {
            const body = new URLSearchParams({ _token: csrfToken });
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

            if (!response.ok || !Array.isArray(payload.participants)) {
                throw new Error('Invalid presence response.');
            }

            renderParticipants(payload.participants);
            updateStatus('Participantes atualizados.');
        } catch {
            updateStatus('Não foi possível atualizar a lista agora. Tentaremos novamente.', true);
        } finally {
            requestInProgress = false;
        }
    };

    refreshPresence();
    intervalId = window.setInterval(refreshPresence, 10000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            refreshPresence();
        }
    });
})();
