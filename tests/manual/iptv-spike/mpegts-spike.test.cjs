'use strict';

const assert = require('node:assert/strict');
const spike = require('./mpegts-spike.js');

assert.equal(spike.isValidCandidateId('0123456789abcdef0123456789abcdef'), true);
assert.equal(spike.isValidCandidateId('../playlist_venlomxo1402_plus.m3u'), false);
assert.equal(spike.isValidCandidateId('0123456789ABCDEF0123456789ABCDEF'), false);
assert.equal(spike.isValidCandidateId('short'), false);

assert.deepEqual(spike.normalizeFeatures({
    msePlayback: 1,
    mseLivePlayback: true,
    mseH265Playback: false,
    networkStreamIO: true,
    networkLoaderName: 'fetch-stream-loader',
    nativeMP4H264Playback: true,
    nativeMP4H265Playback: false,
    ignored: 'secret',
}), {
    msePlayback: true,
    mseLivePlayback: true,
    mseH265Playback: false,
    networkStreamIO: true,
    networkLoaderName: 'fetch-stream-loader',
    nativeMP4H264Playback: true,
    nativeMP4H265Playback: false,
});

const original = spike.initialState();
original.results.LIVE_H264_1.playback = 'success';
const reset = spike.resetState();
assert.equal(reset.results.LIVE_H264_1.playback, 'not-tested');
assert.notEqual(original.results.LIVE_H264_1, reset.results.LIVE_H264_1);

const state = spike.initialState();
state.results.LIVE_H264_1.playback = 'success';
state.results.LIVE_H264_1.videoCodec = 'avc1.640028';
state.results.LIVE_H264_1.error = 'https://private.example/live/account/password/123?token=secret-token';
const report = spike.buildReport(state, {
    msePlayback: true,
    mseLivePlayback: true,
    mseH265Playback: false,
    networkStreamIO: true,
    networkLoaderName: 'fetch-stream-loader',
    nativeMP4H264Playback: true,
    nativeMP4H265Playback: false,
}, 'Test Browser https://browser.invalid');

assert.match(report, /^MPEGTS\.JS PLAYBACK REPORT/m);
assert.match(report, /Library: mpegts\.js 1\.8\.2/);
assert.match(report, /msePlayback: yes/);
assert.match(report, /networkLoaderName: fetch-stream-loader/);
assert.match(report, /Continuous MPEG-TS Live: possible/);
assert.match(report, /Mixed content: blocking risk/);
assert.match(report, /Credentials exposure in browser network: possible/);
assert.equal(report.includes('private.example'), false);
assert.equal(report.includes('account'), false);
assert.equal(report.includes('password'), false);
assert.equal(report.includes('secret-token'), false);
assert.equal(report.includes('https://'), false);

const sanitized = spike.sanitizeDiagnostic(
    'Network https://private.invalid/live?id=1 candidate 0123456789abcdef0123456789abcdef',
);
assert.equal(sanitized.includes('private.invalid'), false);
assert.equal(sanitized.includes('0123456789abcdef0123456789abcdef'), false);

console.log('mpegts-spike tests passed');
