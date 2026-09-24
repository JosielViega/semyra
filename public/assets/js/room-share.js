'use strict';

(() => {
    const shareContainer = document.querySelector('[data-room-share]');
    if (!shareContainer) {
        return;
    }

    const urlField = document.getElementById('room-share-url');
    const copyButton = document.getElementById('room-copy-link');
    const shareButton = document.getElementById('room-native-share');
    const statusElement = document.getElementById('room-share-status');

    if (!urlField || !copyButton || !shareButton || !statusElement) {
        return;
    }

    const currentUrl = new URL(window.location.href);
    const roomUrl = `${currentUrl.origin}${currentUrl.pathname}`;
    const roomCode = shareContainer.dataset.roomCode ?? '';

    urlField.value = roomUrl;

    const updateStatus = (message) => {
        statusElement.textContent = message;
    };

    const selectUrlForManualCopy = () => {
        urlField.focus();
        urlField.select();
        urlField.setSelectionRange(0, urlField.value.length);
        updateStatus('Não foi possível copiar automaticamente. O link foi selecionado para você copiar.');
    };

    copyButton.addEventListener('click', async () => {
        if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
            selectUrlForManualCopy();
            return;
        }

        try {
            await navigator.clipboard.writeText(roomUrl);
            updateStatus('Link copiado.');
        } catch {
            selectUrlForManualCopy();
        }
    });

    if (typeof navigator.share !== 'function') {
        return;
    }

    shareButton.hidden = false;
    shareButton.addEventListener('click', async () => {
        try {
            await navigator.share({
                title: `Sala ${roomCode} — Semyra`,
                text: 'Assista comigo no Semyra.',
                url: roomUrl,
            });
            updateStatus('Sala compartilhada.');
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                updateStatus('Compartilhamento cancelado.');
                return;
            }

            updateStatus('Não foi possível abrir o compartilhamento.');
        }
    });
})();
