'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const playback = fs.readFileSync(path.join(root, 'public/assets/js/room-playback.js'), 'utf8');
const shell = fs.readFileSync(path.join(root, 'public/assets/js/room-shell.js'), 'utf8');

assert.doesNotMatch(playback, /toggleButton\.textContent\s*=/);
assert.match(playback, /playIcon\.toggleAttribute\('hidden', playing\)/);
assert.match(playback, /pauseIcon\.toggleAttribute\('hidden', !playing\)/);
assert.match(playback, /toggleButton\.dataset\.state = playing \? 'playing' : 'paused'/);

assert.doesNotMatch(shell, /muteButton\.textContent\s*=/);
assert.match(shell, /mutedIcon\.toggleAttribute\('hidden', !muted\)/);
assert.match(shell, /audibleIcon\.toggleAttribute\('hidden', muted\)/);
assert.match(shell, /muteButton\.setAttribute\('aria-pressed', String\(muted\)\)/);
assert.match(shell, /maximizeIcon\.toggleAttribute\('hidden', fullscreen\)/);
assert.match(shell, /minimizeIcon\.toggleAttribute\('hidden', !fullscreen\)/);
assert.match(shell, /flash\.dataset\.flashType === 'error' \? 5000 : 2000/);
assert.match(shell, /reducedMotion \? 0 : 200/);
assert.match(shell, /flash\.classList\.add\('is-leaving'\)/);
assert.match(shell, /flash\.remove\(\)/);

console.log('room-icons tests passed');
