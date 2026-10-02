'use strict';

(() => {
    const shell = document.querySelector('[data-room-shell]');
    if (!shell) {
        return;
    }

    const flashes = Array.from(shell.querySelectorAll('[data-room-flash]'));
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
    flashes.forEach((flash) => {
        const visibleFor = flash.dataset.flashType === 'error' ? 5000 : 2000;
        window.setTimeout(() => {
            flash.classList.add('is-leaving');
            window.setTimeout(() => flash.remove(), reducedMotion ? 0 : 200);
        }, visibleFor);
    });

    const dialog = document.getElementById('room-transmission-dialog');
    const emptyState = shell.querySelector('[data-room-empty-state]');
    const ownerCopy = shell.querySelector('[data-room-owner]');
    const endForm = shell.querySelector('[data-end-transmission]');
    const endInstanceInput = shell.querySelector('[data-end-transmission-instance-id]');
    const endRevisionInput = shell.querySelector('[data-end-transmission-revision]');
    const replaceWarning = shell.querySelector('[data-replace-warning]');
    const replaceOwner = shell.querySelector('[data-replace-owner]');
    const muteButton = shell.querySelector('[data-mute-toggle]');
    const mutedIcon = muteButton?.querySelector('[data-icon-muted]');
    const audibleIcon = muteButton?.querySelector('[data-icon-audible]');
    const volumeInput = shell.querySelector('[data-volume-control]');
    const fullscreenButton = shell.querySelector('[data-fullscreen-toggle]');
    const maximizeIcon = fullscreenButton?.querySelector('[data-icon-maximize]');
    const minimizeIcon = fullscreenButton?.querySelector('[data-icon-minimize]');
    const panels = Array.from(shell.querySelectorAll('[data-room-panel]'));
    const panelToggles = Array.from(shell.querySelectorAll('[data-panel-toggle]'));
    let hideTimer = null;
    let currentTransmission = null;
    let hasAppliedTransmission = false;
    let playbackInteraction = false;
    let localControlInteraction = false;

    const anyInteractionOpen = () => (dialog instanceof HTMLDialogElement && dialog.open)
        || panels.some((panel) => !panel.hidden)
        || playbackInteraction
        || localControlInteraction;

    const revealHud = () => {
        shell.classList.remove('is-hud-hidden');
        if (hideTimer !== null) {
            window.clearTimeout(hideTimer);
        }

        if (!shell.classList.contains('has-transmission') || anyInteractionOpen()) {
            return;
        }

        hideTimer = window.setTimeout(() => {
            if (!anyInteractionOpen()) {
                shell.classList.add('is-hud-hidden');
            }
        }, 2800);
    };

    const closePanels = () => {
        panels.forEach((panel) => {
            panel.hidden = true;
        });
        panelToggles.forEach((button) => button.setAttribute('aria-expanded', 'false'));
        revealHud();
    };

    const openDialog = () => {
        closePanels();
        if (dialog instanceof HTMLDialogElement && !dialog.open) {
            dialog.showModal();
        }
        revealHud();
    };

    shell.querySelectorAll('[data-open-transmission]').forEach((button) => {
        button.addEventListener('click', openDialog);
    });

    shell.querySelectorAll('[data-close-transmission]').forEach((button) => {
        button.addEventListener('click', () => dialog?.close());
    });

    dialog?.addEventListener('close', revealHud);
    dialog?.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    panelToggles.forEach((button) => {
        button.addEventListener('click', () => {
            const requested = button.dataset.panelToggle;
            const target = panels.find((panel) => panel.dataset.roomPanel === requested);
            const shouldOpen = target?.hidden === true;
            closePanels();
            if (target && shouldOpen) {
                target.hidden = false;
                button.setAttribute('aria-expanded', 'true');
            }
            revealHud();
        });
    });

    shell.querySelectorAll('[data-panel-close]').forEach((button) => {
        button.addEventListener('click', closePanels);
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)
            || target.closest('[data-room-panel]')
            || target.closest('[data-panel-toggle]')) {
            return;
        }
        if (panels.some((panel) => !panel.hidden)) {
            closePanels();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closePanels();
        }
        revealHud();
    });

    ['mousemove', 'mousedown', 'touchstart'].forEach((eventName) => {
        document.addEventListener(eventName, revealHud, { passive: true });
    });

    muteButton?.addEventListener('click', () => {
        document.dispatchEvent(new CustomEvent('semyra:player-mute-toggle'));
        revealHud();
    });

    volumeInput?.addEventListener('input', () => {
        document.dispatchEvent(new CustomEvent('semyra:player-volume-change', {
            detail: { volume: Number(volumeInput.value) },
        }));
        revealHud();
    });
    volumeInput?.addEventListener('focus', () => {
        localControlInteraction = true;
        revealHud();
    });
    volumeInput?.addEventListener('blur', () => {
        localControlInteraction = false;
        revealHud();
    });

    document.addEventListener('semyra:player-audio-state', (event) => {
        const muted = event.detail?.muted === true;
        const volume = Number(event.detail?.volume);
        if (muteButton) {
            muteButton.dataset.state = muted ? 'muted' : 'audible';
            muteButton.setAttribute('aria-label', muted ? 'Ativar som' : 'Silenciar');
            muteButton.setAttribute('aria-pressed', String(muted));
            if (mutedIcon) {
                mutedIcon.toggleAttribute('hidden', !muted);
            }
            if (audibleIcon) {
                audibleIcon.toggleAttribute('hidden', muted);
            }
        }
        if (volumeInput && Number.isFinite(volume)) {
            volumeInput.value = String(Math.min(100, Math.max(0, Math.round(volume))));
            volumeInput.setAttribute(
                'aria-valuetext',
                `${volumeInput.value}%${muted ? ', sem som' : ''}`,
            );
        }
    });

    fullscreenButton?.addEventListener('click', async () => {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await shell.requestFullscreen();
            }
        } catch {
            // Fullscreen is a local progressive enhancement.
        }
        revealHud();
    });

    document.addEventListener('fullscreenchange', () => {
        const fullscreen = document.fullscreenElement !== null;
        fullscreenButton?.setAttribute('data-state', fullscreen ? 'fullscreen' : 'windowed');
        fullscreenButton?.setAttribute(
            'aria-label',
            fullscreen ? 'Sair da tela cheia' : 'Entrar em tela cheia',
        );
        if (maximizeIcon) {
            maximizeIcon.toggleAttribute('hidden', fullscreen);
        }
        if (minimizeIcon) {
            minimizeIcon.toggleAttribute('hidden', !fullscreen);
        }
    });

    document.addEventListener('semyra:hud-interaction-start', () => {
        playbackInteraction = true;
        revealHud();
    });
    document.addEventListener('semyra:hud-interaction-end', () => {
        playbackInteraction = false;
        revealHud();
    });

    const sameTransmission = (left, right) => left === right
        || (left !== null
            && right !== null
            && left.instanceId === right.instanceId
            && left.source === right.source
            && left.videoId === right.videoId
            && left.revision === right.revision
            && left.ownerName === right.ownerName
            && left.isOwner === right.isOwner
            && left.mediaMode === right.mediaMode);

    const applyTransmission = (transmission) => {
        if (hasAppliedTransmission && sameTransmission(currentTransmission, transmission)) {
            return;
        }

        hasAppliedTransmission = true;
        currentTransmission = transmission;
        const active = transmission !== null;
        shell.classList.toggle('has-transmission', active);
        emptyState?.toggleAttribute('hidden', active);
        endForm?.toggleAttribute('hidden', !transmission?.isOwner);
        if (endInstanceInput) {
            endInstanceInput.value = transmission?.instanceId ?? '';
        }
        if (endRevisionInput) {
            endRevisionInput.value = transmission === null ? '' : String(transmission.revision);
        }
        replaceWarning?.toggleAttribute('hidden', !active);

        if (ownerCopy) {
            ownerCopy.textContent = active
                ? `${transmission.isOwner ? 'Você' : transmission.ownerName} está transmitindo`
                : 'Sem transmissão ativa';
        }
        if (replaceOwner && active) {
            replaceOwner.textContent = transmission.ownerName;
        }

        document.dispatchEvent(new CustomEvent('semyra:transmission-updated', {
            detail: { transmission: currentTransmission },
        }));
        revealHud();
    };

    document.addEventListener('semyra:presence-updated', (event) => {
        applyTransmission(event.detail?.transmission ?? null);
    });

    const initialRevision = Number(shell.dataset.initialRevision);
    const initialInstanceId = shell.dataset.initialInstanceId ?? '';
    const initialTransmission = /^[a-f0-9]{32}$/.test(initialInstanceId)
        && Number.isSafeInteger(initialRevision) && initialRevision > 0
        ? {
            source: shell.dataset.initialSource ?? '',
            instanceId: initialInstanceId,
            videoId: shell.dataset.initialSource === 'youtube'
                ? (shell.dataset.initialVideoId ?? '')
                : null,
            revision: initialRevision,
            ownerName: shell.dataset.initialOwnerName || 'Participante',
            isOwner: shell.dataset.initialIsOwner === '1',
            mediaMode: shell.dataset.initialMediaMode ?? '',
            playback: {
                state: shell.dataset.initialPlaybackState ?? '',
                positionMs: Number(shell.dataset.initialPlaybackPositionMs),
                revision: Number(shell.dataset.initialPlaybackRevision),
                atLiveEdge: shell.dataset.initialPlaybackAtLiveEdge === '1',
                liveEdgePositionMs: shell.dataset.initialLiveEdgePositionMs === ''
                    ? null
                    : Number(shell.dataset.initialLiveEdgePositionMs),
                liveSyncPositionMs: shell.dataset.initialLiveSyncPositionMs === ''
                    ? null
                    : Number(shell.dataset.initialLiveSyncPositionMs),
                liveSyncDelayMs: shell.dataset.initialLiveSyncDelayMs === ''
                    ? null
                    : Number(shell.dataset.initialLiveSyncDelayMs),
            },
        }
        : null;
    applyTransmission(initialTransmission);
})();
