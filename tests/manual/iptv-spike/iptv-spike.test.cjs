'use strict';

const assert = require('node:assert/strict');
const spike = require('./iptv-spike.js');

const playlist = `#EXTM3U
#EXTINF:-1 tvg-id="canal-1" tvg-name="Canal Um" tvg-logo="https://img.invalid/1.png" group-title="Notícias",Canal 1
https://media.invalid/live/1.m3u8
#UNKNOWN ignored
#EXTINF:-1 group-title="Filmes",Filme autorizado
https://media.invalid/movie/2.mp4
https://media.invalid/unlabelled.ts`;

assert.deepEqual(spike.parseM3u(playlist), [
    {
        tvgId: 'canal-1',
        tvgName: 'Canal Um',
        tvgLogo: 'https://img.invalid/1.png',
        groupTitle: 'Notícias',
        name: 'Canal 1',
        url: 'https://media.invalid/live/1.m3u8',
    },
    {
        tvgId: '',
        tvgName: '',
        tvgLogo: '',
        groupTitle: 'Filmes',
        name: 'Filme autorizado',
        url: 'https://media.invalid/movie/2.mp4',
    },
    {
        tvgId: '',
        tvgName: '',
        tvgLogo: '',
        groupTitle: '',
        name: 'Item sem nome',
        url: 'https://media.invalid/unlabelled.ts',
    },
]);

const redacted = spike.redactSensitive(
    'https://provider.invalid/live/user.name/p@ss word/10.m3u8?token=abc&username=user.name&password=p%40ss%20word',
    ['user.name', 'p@ss word'],
);
assert.equal(redacted.includes('user.name'), false);
assert.equal(redacted.includes('p@ss word'), false);
assert.equal(redacted.includes('p%40ss%20word'), false);
assert.equal(redacted.includes('token=abc'), false);
assert.match(redacted, /token=\*\*\*/);

assert.equal(
    spike.detectUrlCredentials('https://provider.invalid/list.m3u?username=account&password=secret', []),
    'yes',
    'playlist query credentials are detected',
);
assert.equal(
    spike.detectUrlCredentials('https://provider.invalid/live/account/secret/10.m3u8', ['account', 'secret']),
    'yes',
    'known media credentials in path are detected',
);
assert.equal(
    spike.detectUrlCredentials('https://provider.invalid/public/10.m3u8', ['account', 'secret']),
    'no',
    'credential-free media URL is classified as no',
);
assert.equal(spike.detectUrlCredentials('not a url', []), 'unknown');

assert.equal(spike.classifyMediaUrl('https://media.invalid/a.m3u8?token=x'), 'hls');
assert.equal(spike.classifyMediaUrl('https://media.invalid/a.mp4'), 'mp4');
assert.equal(spike.classifyMediaUrl('https://media.invalid/a.ts'), 'ts');
assert.equal(spike.classifyMediaUrl('https://media.invalid/a.mkv'), 'other-vod');
assert.equal(spike.classifyMediaUrl('https://media.invalid/no-extension', 'hls'), 'hls');
assert.equal(spike.detectedMediaType('https://media.invalid/a.m3u8?token=x'), 'hls-m3u8');
assert.equal(spike.detectedMediaType('https://media.invalid/a.ts?key=x'), 'mpeg-ts');
assert.equal(spike.detectedMediaType('https://media.invalid/a.mp4?auth=x'), 'mp4');
assert.equal(spike.detectedMediaType('https://media.invalid/a.mkv'), 'other');
assert.equal(spike.detectedMediaType('https://media.invalid/a.avi'), 'other');
assert.equal(spike.detectedMediaType('https://media.invalid/a.m4v'), 'other');
assert.equal(spike.detectedMediaType('https://media.invalid/a.webm'), 'other');
assert.equal(spike.detectedMediaType('https://media.invalid/no-extension', 'hls'), 'unknown');

assert.equal(spike.detectMixedContent('https:', 'http://provider.invalid/live'), true);
assert.equal(spike.detectMixedContent('http:', 'http://provider.invalid/live'), false);
assert.equal(spike.detectMixedContent('https:', 'https://provider.invalid/live'), false);
assert.equal(spike.futureHttpsRisk('http'), 'risk');
assert.equal(spike.futureHttpsRisk('https'), 'no-obvious-risk');
assert.equal(spike.futureHttpsRisk('other'), 'unknown');

const failedPlayback = spike.applyPlaybackFailure(spike.initialReportState(), {
    stage: 'native-load',
    mediaErrorCode: 4,
});
assert.equal(failedPlayback.playback, 'fail');
assert.equal(failedPlayback.playbackFailureStage, 'native-load');
assert.equal(failedPlayback.fatalErrorType, 'browser-media-error');
assert.equal(failedPlayback.fatalErrorDetails, 'MediaError.code=4');
assert.notEqual(failedPlayback.fatalErrorType, 'none');
assert.notEqual(failedPlayback.fatalErrorDetails, 'none');

const report = spike.buildSanitizedReport({
    sourceMode: 'xtream',
    providerProtocol: 'http',
    xtreamAuth: 'success',
    allowedFormats: ['m3u8', 'ts', 'https://must-not-appear.invalid'],
    liveTested: true,
    liveFormat: 'm3u8',
    credentialsInPlaylistUrl: 'yes',
    credentialsInMediaUrl: 'yes',
    m3uPlaylistFetch: 'success',
    m3uParsedItems: 12,
    remoteM3uProtocol: 'https',
    mediaTested: true,
    mediaProtocol: 'https',
    detectedMediaType: 'hls-m3u8',
    playbackMethod: 'hls.js',
    fatalErrorType: 'networkError',
    fatalErrorDetails: 'https://provider.invalid/live/account/secret/10.m3u8?token=private-token',
}, {
    browser: 'Test Browser',
    pageProtocol: 'http',
    mediaSource: true,
    hlsJs: true,
}, ['account', 'secret', 'private-token']);
assert.match(report, /^IPTV SPIKE REPORT/m);
assert.match(report, /Allowed formats: m3u8, ts/);
assert.match(report, /Credentials in playlist URL: yes/);
assert.match(report, /Media URL credentials: yes/);
assert.match(report, /M3U playlist fetch: success/);
assert.match(report, /M3U parsed items: 12/);
assert.match(report, /Detected media type: hls-m3u8/);
assert.match(report, /Playback method: hls\.js/);
assert.equal(report.includes('provider.invalid'), false);
assert.equal(report.includes('must-not-appear.invalid'), false);
assert.equal(report.includes('account'), false);
assert.equal(report.includes('secret'), false);
assert.equal(report.includes('private-token'), false);
assert.equal(report.includes('https://'), false);
assert.equal(report.includes('username'), false);
assert.equal(report.includes('password'), false);

console.log('iptv-spike tests passed');
