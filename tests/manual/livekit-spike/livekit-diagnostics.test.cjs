'use strict';

const assert = require('node:assert/strict');
const diagnostics = require('./livekit-diagnostics.js');

const report = new Map([
  ['out', { id: 'out', type: 'outbound-rtp', kind: 'audio', packetsSent: 990, bytesSent: 123456, remoteId: 'remote' }],
  ['remote', { id: 'remote', type: 'remote-inbound-rtp', kind: 'audio', packetsLost: 10, jitter: 0.012, roundTripTime: 0.085 }],
  ['in', { id: 'in', type: 'inbound-rtp', kind: 'audio', packetsLost: 5, packetsReceived: 995, bytesReceived: 654321, jitter: 0.008, jitterBufferDelay: 2, jitterBufferEmittedCount: 1000 }],
  ['pair', { id: 'pair', type: 'candidate-pair', state: 'succeeded', nominated: true, localCandidateId: 'candidate' }],
  ['candidate', { id: 'candidate', type: 'local-candidate', protocol: 'udp', candidateType: 'relay', address: 'must-not-leak.example' }],
]);

const sender = diagnostics.extractSender(null, report);
assert.equal(sender.roundTripTimeMs, 85);
assert.equal(sender.jitterMs, 12);
assert.equal(sender.packetsLost, 10);
assert.equal(sender.packetsSent, 990);
assert.equal(sender.lossPercent, 1);

const receiver = diagnostics.extractReceiver(null, report);
assert.equal(receiver.jitterMs, 8);
assert.equal(receiver.jitterBufferMs, 2);
assert.equal(receiver.lossPercent, 0.5);

assert.deepEqual(diagnostics.extractSafeTransport(report), { protocol: 'udp', candidateType: 'relay' });
assert.equal(JSON.stringify(diagnostics.extractSafeTransport(report)).includes('must-not-leak'), false);
assert.equal(diagnostics.normalizeQuality(1, { 1: 'Excellent' }), 'excellent');
assert.equal(diagnostics.normalizeQuality('unexpected'), 'unknown');
assert.equal(diagnostics.formatLoss(5, 0.5), '5 packets / 0.50%');
assert.equal(diagnostics.formatMilliseconds(null), 'unavailable');

console.log('LiveKit diagnostics: 15 assertions passed.');
