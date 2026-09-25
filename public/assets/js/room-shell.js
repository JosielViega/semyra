'use strict';

(() => {
    const shell = document.querySelector('[data-room-shell]');
    if (!shell) {
        return;
    }

    const dialog = document.getElementById('room-transmission-dialog');
    const emptyState = shell.querySelector('[data-room-empty-state]');
    const ownerCopy = shell.querySelector('[data-room-owner]');
    const endForm = shell.querySelector('[data-end-transmission]');
    const replaceWarning = shell.querySelector('[data-replace-warning]');
    const replaceOwner = shell.querySelector('[data-replace-owner]');
    const muteButton = shell.querySelector('[data-mute-toggle]');
    const fullscreenButton = shell.querySelector('[data-fullscreen-toggle]');
    const panels = Array.from(shell.querySelectorAll('[data-room-panel]'));
    const panelToggles = Array.from(shell.querySelectorAll('[data-panel-toggle]'));
    let hideTimer = null;
    let currentTransmission = null;
    let hasAppliedTransmission = false;

    const anyInteractionOpen = () => (dialog instanceof HTMLDialogElement && dialog.open)
        || panels.some((panel) => !panel.hidden);

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

    document.addEventListener('semyra:player-muted-state', (event) => {
        const muted = event.detail?.muted === true;
        if (muteButton) {
            muteButton.textContent = muted ? '🔇' : '🔊';
            muteButton.setAttribute('aria-label', muted ? 'Ativar som' : 'Silenciar');
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
        fullscreenButton?.setAttribute(
            'aria-label',
            document.fullscreenElement ? 'Sair da tela cheia' : 'Entrar em tela cheia',
        );
    });

    const sameTransmission = (left, right) => left === right
        || (left !== null
            && right !== null
            && left.source === right.source
            && left.videoId === right.videoId
            && left.revision === right.revision
            && left.ownerName === right.ownerName
            && left.isOwner === right.isOwner);

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
    const initialTransmission = Number.isSafeInteger(initialRevision) && initialRevision > 0
        ? {
            source: shell.dataset.initialSource ?? '',
            videoId: shell.dataset.initialVideoId ?? '',
            revision: initialRevision,
            ownerName: shell.dataset.initialOwnerName || 'Participante',
            isOwner: shell.dataset.initialIsOwner === '1',
        }
        : null;
    applyTransmission(initialTransmission);
})();
