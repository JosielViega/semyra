'use strict';

(() => {
    const telemetryContainer = document.querySelector('[data-room-telemetry]');
    if (!telemetryContainer) {
        return;
    }

    const telemetryList = document.getElementById('room-telemetry-list');
    if (!telemetryList) {
        return;
    }

    const stateLabels = new Map([
        [-1, 'Não iniciado'],
        [0, 'Finalizado'],
        [1, 'Reproduzindo'],
        [2, 'Pausado'],
        [3, 'Bufferizando'],
        [5, 'Preparado'],
    ]);

    const formatTime = (milliseconds) => {
        const totalTenths = Math.round(milliseconds / 100);
        const tenths = totalTenths % 10;
        const totalSeconds = Math.floor(totalTenths / 10);
        const seconds = totalSeconds % 60;
        const totalMinutes = Math.floor(totalSeconds / 60);
        const minutes = totalMinutes % 60;
        const hours = Math.floor(totalMinutes / 60);
        const minuteText = String(minutes).padStart(2, '0');
        const secondText = String(seconds).padStart(2, '0');

        return hours > 0
            ? `${hours}:${minuteText}:${secondText}.${tenths}`
            : `${minuteText}:${secondText}.${tenths}`;
    };

    const describeComparison = (participant) => {
        if (participant.is_you && participant.playback.drift_ms === 0) {
            return 'referência';
        }

        if (typeof participant.playback.drift_ms !== 'number') {
            return 'comparação indisponível';
        }

        const driftSeconds = participant.playback.drift_ms / 1000;
        const sign = driftSeconds >= 0 ? '+' : '';
        return `${sign}${driftSeconds.toFixed(1)} s vs você`;
    };

    const describePlayback = (participant) => {
        const playback = participant.playback;
        if (playback === null) {
            return 'Sem dados do player';
        }

        if (!playback.fresh) {
            return 'Sem dados recentes do player';
        }

        const state = stateLabels.get(playback.state) ?? 'Estado indisponível';
        return `${state} · ${formatTime(playback.position_ms)} · ${describeComparison(participant)}`;
    };

    const render = (participants) => {
        const fragment = document.createDocumentFragment();

        participants.forEach((participant) => {
            const item = document.createElement('li');
            const name = document.createElement('strong');
            const playback = document.createElement('span');

            name.textContent = participant.is_you ? `${participant.name} (você)` : participant.name;
            playback.textContent = describePlayback(participant);
            item.append(name, playback);
            fragment.append(item);
        });

        telemetryList.replaceChildren(fragment);
    };

    document.addEventListener('semyra:presence-updated', (event) => {
        const participants = event.detail?.participants;
        if (Array.isArray(participants)) {
            render(participants);
        }
    });
})();
