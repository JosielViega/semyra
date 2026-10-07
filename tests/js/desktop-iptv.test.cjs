'use strict';

const assert = require('node:assert/strict');
const iptv = require('../../public/assets/js/desktop-iptv.js');

assert.equal(typeof iptv.createIptvCatalog, 'function');
assert.equal(iptv.mediaStatusLabel('preparing'), 'Preparando canal...');
assert.equal(iptv.mediaStatusLabel('streaming'), 'Canal conectado.');
assert.equal(iptv.mediaStatusLabel('reconnecting'), 'Reconectando...');
assert.equal(iptv.mediaStatusLabel('failed', 'media_runtime_unavailable'), 'Runtime de mídia indisponível.');
assert.equal(iptv.mediaStatusLabel('failed', 'invalid_mpegts'), 'Não foi possível abrir o canal.');
const browserOnly = iptv.createIptvCatalog({
    document: {querySelector: () => null},
    addEventListener: () => {},
});
assert.equal(browserOnly.start(), false);

console.log('desktop-iptv tests passed');
