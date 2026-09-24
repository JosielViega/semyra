'use strict';

(() => {
    const playerElement = document.getElementById('youtube-player');
    if (!playerElement) {
        return;
    }

    const statusElement = document.getElementById('youtube-player-status');
    const videoId = playerElement.dataset.videoId ?? '';
    const videoIdPattern = /^[A-Za-z0-9_-]{11}$/;
    const apiUrl = 'https://www.youtube.com/iframe_api';
    let playerCreated = false;

    const updateStatus = (message, isError = false) => {
        if (!statusElement) {
            return;
        }

        statusElement.textContent = message;
        statusElement.classList.toggle('youtube-player-status-error', isError);
    };

    const messageForError = (code) => {
        switch (code) {
            case 2:
                return 'Não foi possível carregar este conteúdo do YouTube.';
            case 5:
                return 'Não foi possível reproduzir este conteúdo neste navegador.';
            case 100:
                return 'Este vídeo não está disponível.';
            case 101:
            case 150:
                return 'Este vídeo não permite reprodução fora do YouTube.';
            case 153:
                return 'Não foi possível carregar o player do YouTube.';
            default:
                return 'Não foi possível carregar o conteúdo do YouTube.';
        }
    };

    const createPlayer = () => {
        if (playerCreated || !window.YT || typeof window.YT.Player !== 'function') {
            return;
        }

        playerCreated = true;

        try {
            new window.YT.Player(playerElement, {
                width: '100%',
                height: '100%',
                videoId,
                playerVars: {
                    autoplay: 0,
                    controls: 1,
                    playsinline: 1,
                    origin: window.location.origin,
                },
                events: {
                    onReady: () => {
                        updateStatus('Player pronto. Use os controles do YouTube para iniciar.');
                    },
                    onError: (event) => {
                        updateStatus(messageForError(event.data), true);
                    },
                },
            });
        } catch {
            updateStatus('Não foi possível carregar o player do YouTube.', true);
        }
    };

    if (!videoIdPattern.test(videoId)) {
        updateStatus('Não foi possível carregar este conteúdo do YouTube.', true);
        return;
    }

    if (window.YT && typeof window.YT.Player === 'function') {
        createPlayer();
        return;
    }

    const previousReadyHandler = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => {
        if (typeof previousReadyHandler === 'function') {
            previousReadyHandler();
        }

        createPlayer();
    };

    const handleApiLoadError = () => {
        updateStatus('Não foi possível carregar o player do YouTube. Verifique sua conexão e tente novamente.', true);
    };

    const existingScript = document.querySelector(`script[src="${apiUrl}"]`);
    if (existingScript) {
        existingScript.addEventListener('error', handleApiLoadError, { once: true });
        return;
    }

    const apiScript = document.createElement('script');
    apiScript.src = apiUrl;
    apiScript.async = true;
    apiScript.addEventListener('error', handleApiLoadError, { once: true });
    document.head.append(apiScript);
})();
