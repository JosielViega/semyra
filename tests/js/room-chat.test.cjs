'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/room-chat.js'), 'utf8');

test('chat renders untrusted sender and message with textContent only', () => {
    assert.match(source, /name\.textContent =/);
    assert.match(source, /body\.textContent = message\.message/);
    assert.doesNotMatch(source, /innerHTML|insertAdjacentHTML/);
});

test('chat uses incremental bounded polling and aborts obsolete requests', () => {
    assert.match(source, /after_id: newestId, limit: 50/);
    assert.match(source, /requestController\?\.abort\(\)/);
    assert.match(source, /!panel\.hidden && !document\.hidden \? 3000 : 9000/);
});

test('chat supports idempotent retry, Enter send and Shift Enter newline', () => {
    assert.match(source, /client_message_id: pendingClientId/);
    assert.match(source, /pendingClientId \?\?= randomId\(\)/);
    assert.match(source, /event\.key === 'Enter' && !event\.shiftKey/);
});

test('failed send keeps composed text', () => {
    const catchBlock = source.slice(source.indexOf('} catch (error) {', source.indexOf("status.textContent = 'Enviando...'")));
    assert.doesNotMatch(catchBlock.split('} finally {')[0], /input\.value\s*=\s*''/);
});
