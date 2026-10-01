(function (root, factory) {
  'use strict';

  const diagnostics = factory();
  if (typeof module === 'object' && module.exports) module.exports = diagnostics;
  if (root) root.LiveKitSpikeDiagnostics = diagnostics;
})(typeof window !== 'undefined' ? window : globalThis, () => {
  'use strict';

  const allowedQualities = new Set(['excellent', 'good', 'poor', 'lost', 'unknown']);
  const allowedProtocols = new Set(['udp', 'tcp']);
  const allowedCandidateTypes = new Set(['host', 'srflx', 'prflx', 'relay']);

  function finite(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
  }

  function secondsToMilliseconds(value) {
    const number = finite(value);
    return number === null ? null : number * 1000;
  }

  function packetLoss(lost, received) {
    const lostCount = finite(lost);
    const receivedCount = finite(received);
    if (lostCount === null) return { count: null, percent: null };
    const total = lostCount + (receivedCount ?? 0);
    return { count: lostCount, percent: total > 0 ? (lostCount / total) * 100 : null };
  }

  function reportValues(report) {
    const values = [];
    if (report && typeof report.forEach === 'function') report.forEach((value) => values.push(value));
    return values;
  }

  function extractSender(summary, report) {
    let outbound = summary && typeof summary === 'object' ? summary : null;
    let remoteInbound = null;
    const values = reportValues(report);
    if (!outbound) outbound = values.find((value) => value.type === 'outbound-rtp' && value.kind !== 'video') ?? null;
    if (outbound && outbound.remoteId && report && typeof report.get === 'function') {
      remoteInbound = report.get(outbound.remoteId) ?? null;
    }
    remoteInbound ??= values.find((value) => value.type === 'remote-inbound-rtp' && value.kind !== 'video') ?? null;

    const packetsLost = finite(summary?.packetsLost) ?? finite(remoteInbound?.packetsLost);
    const packetsSent = finite(summary?.packetsSent) ?? finite(outbound?.packetsSent);
    return {
      roundTripTimeMs: secondsToMilliseconds(finite(summary?.roundTripTime) ?? finite(remoteInbound?.roundTripTime)),
      jitterMs: secondsToMilliseconds(finite(summary?.jitter) ?? finite(remoteInbound?.jitter)),
      packetsLost,
      packetsSent,
      bytesSent: finite(summary?.bytesSent) ?? finite(outbound?.bytesSent),
      lossPercent: packetLoss(packetsLost, packetsSent).percent,
    };
  }

  function extractReceiver(summary, report) {
    const values = reportValues(report);
    const inbound = values.find((value) => value.type === 'inbound-rtp' && value.kind !== 'video') ?? null;
    const packetsLost = finite(inbound?.packetsLost) ?? finite(summary?.packetsLost);
    const packetsReceived = finite(inbound?.packetsReceived) ?? finite(summary?.packetsReceived);
    const emitted = finite(inbound?.jitterBufferEmittedCount);
    const totalDelay = finite(inbound?.jitterBufferDelay);
    const averageJitterBufferSeconds = emitted !== null && emitted > 0 && totalDelay !== null
      ? totalDelay / emitted
      : null;
    return {
      jitterMs: secondsToMilliseconds(finite(inbound?.jitter) ?? finite(summary?.jitter)),
      packetsLost,
      packetsReceived,
      bytesReceived: finite(inbound?.bytesReceived) ?? finite(summary?.bytesReceived),
      jitterBufferMs: secondsToMilliseconds(averageJitterBufferSeconds),
      lossPercent: packetLoss(packetsLost, packetsReceived).percent,
    };
  }

  function extractSafeTransport(report) {
    const values = reportValues(report);
    const pair = values.find((value) => value.type === 'candidate-pair' && value.nominated && value.state === 'succeeded')
      ?? values.find((value) => value.type === 'candidate-pair' && value.state === 'succeeded');
    if (!pair || !report || typeof report.get !== 'function') return { protocol: null, candidateType: null };
    const candidate = report.get(pair.localCandidateId);
    const protocol = typeof candidate?.protocol === 'string' ? candidate.protocol.toLowerCase() : null;
    const candidateType = typeof candidate?.candidateType === 'string' ? candidate.candidateType.toLowerCase() : null;
    return {
      protocol: allowedProtocols.has(protocol) ? protocol : null,
      candidateType: allowedCandidateTypes.has(candidateType) ? candidateType : null,
    };
  }

  function normalizeQuality(value, qualityEnum) {
    let quality = typeof value === 'string' ? value : qualityEnum?.[value];
    quality = typeof quality === 'string' ? quality.toLowerCase() : 'unknown';
    return allowedQualities.has(quality) ? quality : 'unknown';
  }

  function formatMilliseconds(value) {
    return finite(value) === null ? 'unavailable' : `${value.toFixed(1)} ms`;
  }

  function formatLoss(count, percent) {
    if (finite(count) === null) return 'unavailable';
    const rate = finite(percent) === null ? 'unavailable' : `${percent.toFixed(2)}%`;
    return `${count} packets / ${rate}`;
  }

  return {
    extractReceiver,
    extractSafeTransport,
    extractSender,
    formatLoss,
    formatMilliseconds,
    normalizeQuality,
    packetLoss,
    secondsToMilliseconds,
  };
});
