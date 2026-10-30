'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const css = fs.readFileSync(path.join(__dirname, '../../public/assets/css/room.css'), 'utf8');
const view = fs.readFileSync(path.join(__dirname, '../../resources/views/pages/room.php'), 'utf8');

test('voice controls expose synchronized toolbar hooks and accessible state labels', () => {
    assert.match(view, /data-voice-toolbar-button/);
    assert.match(view, /data-voice-toolbar-icon/);
    assert.match(view, /data-microphone-state="muted"/);
    assert.match(view, /data-voice-microphone[^>]+aria-pressed="false"[^>]+aria-label="Ativar microfone"/);
    assert.match(view, /data-voice-listen[^>]+aria-pressed="false"[^>]+aria-label="Silenciar participantes"/);
});

test('room communication panels have scoped responsive and stateful polish', () => {
    assert.match(css, /\.room-voice-button\[data-microphone-state="active"\]/);
    assert.match(css, /\.room-voice-control-button\[data-state="active"\]/);
    assert.match(css, /--voice-volume-progress/);
    assert.match(css, /\.room-chat-message\.is-you/);
    assert.match(css, /@media \(max-width: 390px\)/);
    assert.match(css, /@media \(max-height: 620px\)/);
});

test('chat composer keeps an explicit label, help association and visible focus treatment', () => {
    assert.match(view, /for="room-chat-message">Mensagem<\/label>/);
    assert.match(view, /aria-describedby="room-chat-help"/);
    assert.match(css, /textarea:focus-visible/);
    assert.match(css, /\.room-chat-form textarea:focus/);
});
