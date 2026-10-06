'use strict';

const assert = require('node:assert/strict');
const iptv = require('../../public/assets/js/desktop-iptv.js');

assert.equal(typeof iptv.createIptvCatalog, 'function');
const browserOnly = iptv.createIptvCatalog({
    document: {querySelector: () => null},
    addEventListener: () => {},
});
assert.equal(browserOnly.start(), false);

console.log('desktop-iptv tests passed');
