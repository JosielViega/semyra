'use strict';

((root, factory) => {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory;
        return;
    }
    factory({window: root, document: root.document, fetch: root.fetch.bind(root)});
})(typeof window !== 'undefined' ? window : globalThis, ({window, document, fetch}) => {
    const shell = document.querySelector('[data-room-shell]');
    const panel = document.querySelector('[data-room-chat]');
    if (!shell || !panel) return null;

    const endpoint = shell.dataset.chatMessagesUrl ?? '';
    const csrfToken = shell.dataset.csrfToken ?? '';
    const list = panel.querySelector('[data-chat-messages]');
    const form = panel.querySelector('[data-chat-form]');
    const input = panel.querySelector('[data-chat-input]');
    const sendButton = panel.querySelector('[data-chat-send]');
    const status = panel.querySelector('[data-chat-status]');
    const olderButton = panel.querySelector('[data-chat-older]');
    const newButton = panel.querySelector('[data-chat-new]');
    const counter = panel.querySelector('[data-chat-counter]');
    const unreadBadge = shell.querySelector('[data-chat-unread]');
    if (!endpoint || !csrfToken || !list || !form || !input || !sendButton || !status) return null;

    let messages = new Map();
    let oldestId = null;
    let newestId = null;
    let unread = 0;
    let loaded = false;
    let submitting = false;
    let pendingClientId = null;
    let pollTimer = null;
    let requestController = null;

    const randomId = () => {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    };

    const isNearBottom = () => list.scrollHeight - list.scrollTop - list.clientHeight < 72;
    const formatTime = (value) => {
        const date = new Date(String(value).replace(' ', 'T') + (String(value).endsWith('Z') ? '' : 'Z'));
        return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('pt-BR', {
            hour: '2-digit', minute: '2-digit',
        }).format(date);
    };
    const createMessage = (message) => {
        const item = document.createElement('li');
        item.className = `room-chat-message${message.is_you ? ' is-you' : ''}`;
        item.dataset.messageId = String(message.id);
        const heading = document.createElement('div');
        const name = document.createElement('strong');
        const time = document.createElement('time');
        const body = document.createElement('p');
        name.textContent = message.is_you ? 'Voce' : message.sender_name;
        time.textContent = formatTime(message.created_at);
        time.dateTime = message.created_at;
        body.textContent = message.message;
        heading.append(name, time);
        item.append(heading, body);
        return item;
    };
    const updateUnread = () => {
        if (!unreadBadge) return;
        unreadBadge.hidden = unread === 0;
        unreadBadge.textContent = unread > 99 ? '99+' : String(unread);
    };
    const appendMessages = (items, prepend = false) => {
        const atBottom = isNearBottom();
        const oldHeight = list.scrollHeight;
        const fragment = document.createDocumentFragment();
        let added = 0;
        for (const message of items) {
            if (!Number.isSafeInteger(message?.id) || messages.has(message.id)) continue;
            messages.set(message.id, message);
            fragment.append(createMessage(message));
            oldestId = oldestId === null ? message.id : Math.min(oldestId, message.id);
            newestId = newestId === null ? message.id : Math.max(newestId, message.id);
            added += 1;
        }
        if (prepend) {
            list.prepend(fragment);
            list.scrollTop += list.scrollHeight - oldHeight;
        } else {
            list.append(fragment);
            if (panel.hidden || !atBottom) {
                unread += added;
                updateUnread();
                newButton?.toggleAttribute('hidden', unread === 0);
            } else {
                list.scrollTop = list.scrollHeight;
            }
        }
        return added;
    };

    const request = async (params = {}) => {
        requestController?.abort();
        requestController = new AbortController();
        const url = new URL(endpoint, window.location.origin);
        Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
        const response = await fetch(url.toString(), {
            credentials: 'same-origin', signal: requestController.signal,
            headers: {'Accept': 'application/json'},
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error ?? 'chat_failed');
        return payload;
    };
    const loadInitial = async () => {
        try {
            status.textContent = 'Carregando mensagens...';
            const payload = await request({limit: 50});
            appendMessages(payload.messages ?? []);
            loaded = true;
            unread = 0;
            updateUnread();
            if (newButton) newButton.hidden = true;
            if (olderButton) olderButton.hidden = payload.has_more !== true;
            list.scrollTop = list.scrollHeight;
            status.textContent = '';
        } catch (error) {
            if (error?.name !== 'AbortError') status.textContent = 'Nao foi possivel carregar o chat.';
        }
    };
    const poll = async () => {
        if (!loaded || newestId === null) return;
        try {
            const payload = await request({after_id: newestId, limit: 50});
            appendMessages(payload.messages ?? []);
        } catch (error) {
            if (error?.name !== 'AbortError') status.textContent = 'Chat desconectado. Tentando novamente...';
        }
    };
    const schedulePoll = () => {
        if (pollTimer !== null) window.clearTimeout(pollTimer);
        const delay = !panel.hidden && !document.hidden ? 3000 : 9000;
        pollTimer = window.setTimeout(async () => {
            await poll();
            schedulePoll();
        }, delay);
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const value = input.value.trim();
        if (!value || submitting) return;
        submitting = true;
        sendButton.disabled = true;
        pendingClientId ??= randomId();
        status.textContent = 'Enviando...';
        try {
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json'},
                body: new URLSearchParams({_token: csrfToken, message: value, client_message_id: pendingClientId}),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.error ?? 'send_failed');
            appendMessages([payload.message]);
            input.value = '';
            counter.textContent = '0/1000';
            pendingClientId = null;
            status.textContent = '';
            unread = 0;
            updateUnread();
            list.scrollTop = list.scrollHeight;
        } catch (error) {
            status.textContent = error?.message === 'rate_limited'
                ? 'Muitas mensagens. Aguarde alguns segundos.'
                : 'Falha no envio. Sua mensagem foi mantida para tentar novamente.';
        } finally {
            submitting = false;
            sendButton.disabled = false;
        }
    });
    input.addEventListener('input', () => {
        counter.textContent = `${Array.from(input.value).length}/1000`;
        if (pendingClientId !== null) pendingClientId = null;
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        }
    });
    olderButton?.addEventListener('click', async () => {
        if (oldestId === null) return;
        try {
            const payload = await request({before_id: oldestId, limit: 50});
            appendMessages(payload.messages ?? [], true);
            olderButton.hidden = payload.has_more !== true;
        } catch (error) {
            if (error?.name !== 'AbortError') status.textContent = 'Nao foi possivel carregar mensagens anteriores.';
        }
    });
    newButton?.addEventListener('click', () => {
        list.scrollTop = list.scrollHeight;
        unread = 0;
        updateUnread();
        newButton.hidden = true;
    });
    document.addEventListener('click', (event) => {
        const toggle = event.target instanceof Element ? event.target.closest('[data-panel-toggle="chat"]') : null;
        if (!toggle) return;
        window.setTimeout(() => {
            if (!panel.hidden) {
                if (!loaded) void loadInitial();
                unread = 0;
                updateUnread();
                if (newButton) newButton.hidden = true;
            }
            schedulePoll();
        });
    });
    document.addEventListener('visibilitychange', schedulePoll);
    window.addEventListener('pagehide', () => {
        requestController?.abort();
        if (pollTimer !== null) window.clearTimeout(pollTimer);
    }, {once: true});
    schedulePoll();

    return {loadInitial, poll, appendMessages, state: () => ({loaded, oldestId, newestId, unread})};
});
