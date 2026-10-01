(() => {
  'use strict';

  const LK = window.LivekitClient;
  const Diagnostics = window.LiveKitSpikeDiagnostics;
  const elements = {
    status: document.querySelector('#viewer-status'),
    ingressStatus: document.querySelector('#ingress-status'),
    videoStatus: document.querySelector('#video-status'),
    audioStatus: document.querySelector('#audio-status'),
    minutes: document.querySelector('#viewer-minutes'),
    connect: document.querySelector('#viewer-connect'),
    disconnect: document.querySelector('#viewer-disconnect'),
    enableAudio: document.querySelector('#viewer-enable-audio'),
    video: document.querySelector('#viewer-video'),
    audio: document.querySelector('#viewer-audio'),
    events: document.querySelector('#viewer-events'),
    videoCodec: document.querySelector('#video-codec'),
    audioCodec: document.querySelector('#audio-codec'),
    resolution: document.querySelector('#video-resolution'),
    fps: document.querySelector('#video-fps'),
    bitrate: document.querySelector('#video-bitrate'),
    loss: document.querySelector('#video-loss'),
    jitter: document.querySelector('#video-jitter'),
    quality: document.querySelector('#viewer-quality'),
  };

  let room = null;
  let connectedAt = null;
  let statsTimer = null;
  const tracks = new Map();
  const previousVideoStats = new Map();

  function addEvent(type) {
    const item = document.createElement('li');
    item.textContent = type;
    elements.events.prepend(item);
    while (elements.events.children.length > 30) elements.events.lastElementChild.remove();
  }

  function updateMinutes() {
    const minutes = connectedAt ? (Date.now() - connectedAt) / 60000 : 0;
    elements.minutes.textContent = `${minutes.toFixed(2).replace('.', ',')} min`;
  }

  function setConnected(connected) {
    elements.connect.disabled = connected;
    elements.disconnect.disabled = !connected;
    elements.status.textContent = connected ? 'Conectado' : 'Desconectado';
  }

  function safeCodecName(mimeType) {
    const value = typeof mimeType === 'string' ? mimeType.toLowerCase() : '';
    if (value === 'video/h264') return 'H264';
    if (value === 'audio/opus') return 'Opus';
    if (value === 'video/vp8') return 'VP8';
    if (value === 'video/vp9') return 'VP9';
    return 'unavailable';
  }

  async function collectTrackStats(track, publication) {
    if (typeof track.getRTCStatsReport !== 'function') return;
    const report = await track.getRTCStatsReport().catch(() => undefined);
    if (!report || typeof report.forEach !== 'function') return;
    let inbound = null;
    const codecs = new Map();
    report.forEach((entry) => {
      if (entry.type === 'inbound-rtp' && (!entry.kind || entry.kind === track.kind)) inbound = entry;
      if (entry.type === 'codec' && typeof entry.mimeType === 'string') codecs.set(entry.id, entry.mimeType);
    });
    if (!inbound) return;
    const codec = safeCodecName(codecs.get(inbound.codecId));

    if (track.kind === LK.Track.Kind.Video) {
      elements.videoCodec.textContent = codec;
      elements.resolution.textContent = Number.isFinite(inbound.frameWidth) && Number.isFinite(inbound.frameHeight)
        ? `${inbound.frameWidth}×${inbound.frameHeight}`
        : 'unavailable';
      elements.fps.textContent = Number.isFinite(inbound.framesPerSecond) ? inbound.framesPerSecond.toFixed(1) : 'unavailable';
      elements.loss.textContent = Diagnostics.formatLoss(inbound.packetsLost, Diagnostics.packetLoss(inbound.packetsLost, inbound.packetsReceived).percent);
      elements.jitter.textContent = Diagnostics.formatMilliseconds(
        Number.isFinite(inbound.jitter) ? inbound.jitter * 1000 : null,
      );
      const previous = previousVideoStats.get(publication.trackSid);
      if (previous && Number.isFinite(inbound.bytesReceived) && Number.isFinite(inbound.timestamp)) {
        const seconds = (inbound.timestamp - previous.timestamp) / 1000;
        const bits = (inbound.bytesReceived - previous.bytesReceived) * 8;
        elements.bitrate.textContent = seconds > 0 && bits >= 0 ? `${(bits / seconds / 1_000_000).toFixed(2)} Mbps` : 'unavailable';
      }
      if (Number.isFinite(inbound.bytesReceived) && Number.isFinite(inbound.timestamp)) {
        previousVideoStats.set(publication.trackSid, { bytesReceived: inbound.bytesReceived, timestamp: inbound.timestamp });
      }
    } else if (track.kind === LK.Track.Kind.Audio) {
      elements.audioCodec.textContent = codec;
    }
  }

  async function collectStats() {
    await Promise.all(Array.from(tracks.values(), ({ track, publication }) => collectTrackStats(track, publication)));
  }

  function attachTrack(track, publication, participant) {
    const element = track.attach();
    element.autoplay = true;
    element.playsInline = true;
    tracks.set(publication.trackSid, { track, publication, element });
    elements.ingressStatus.textContent = 'Conectado';
    elements.quality.textContent = Diagnostics.normalizeQuality(participant?.connectionQuality, LK.ConnectionQuality);
    if (track.kind === LK.Track.Kind.Video) {
      elements.video.replaceChildren(element);
      elements.videoStatus.textContent = 'Subscribed';
      addEvent('Video subscribed');
    } else if (track.kind === LK.Track.Kind.Audio) {
      elements.audio.append(element);
      elements.audioStatus.textContent = 'Subscribed';
      element.play().catch(() => { elements.enableAudio.hidden = false; });
      addEvent('Audio subscribed');
    }
    collectTrackStats(track, publication);
  }

  function detachTrack(publication) {
    const entry = tracks.get(publication.trackSid);
    if (!entry) return;
    entry.track.detach(entry.element);
    entry.element.remove();
    tracks.delete(publication.trackSid);
    previousVideoStats.delete(publication.trackSid);
    if (entry.track.kind === LK.Track.Kind.Video) elements.videoStatus.textContent = 'Encerrado';
    if (entry.track.kind === LK.Track.Kind.Audio) elements.audioStatus.textContent = 'Encerrado';
  }

  function bindRoomEvents(target) {
    const E = LK.RoomEvent;
    target
      .on(E.Connected, () => {
        connectedAt = Date.now();
        setConnected(true);
        addEvent('Viewer connected');
      })
      .on(E.ParticipantConnected, () => {
        elements.ingressStatus.textContent = 'Conectado';
        addEvent('Ingress participant connected');
      })
      .on(E.ParticipantDisconnected, () => {
        elements.ingressStatus.textContent = 'Desconectado';
        addEvent('Ingress participant disconnected');
      })
      .on(E.TrackSubscribed, attachTrack)
      .on(E.TrackUnsubscribed, (_track, publication) => {
        detachTrack(publication);
        addEvent('Ingress track unsubscribed');
      })
      .on(E.ConnectionQualityChanged, (quality) => {
        elements.quality.textContent = Diagnostics.normalizeQuality(quality, LK.ConnectionQuality);
      })
      .on(E.Disconnected, () => {
        connectedAt = null;
        setConnected(false);
        addEvent('Viewer disconnected');
      });
  }

  async function connect() {
    elements.connect.disabled = true;
    elements.status.textContent = 'Conectando';
    try {
      const response = await fetch('/iptv-viewer-token.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: '{}',
      });
      if (!response.ok) throw new Error('viewer-token-failed');
      let details = await response.json();
      room = new LK.Room({ adaptiveStream: true });
      bindRoomEvents(room);
      await room.connect(details.server_url, details.participant_token, { autoSubscribe: true });
      details = null;
      statsTimer = window.setInterval(collectStats, 2000);
    } catch (_error) {
      elements.status.textContent = 'Falha';
      elements.connect.disabled = false;
      addEvent('Viewer connection failed');
    }
  }

  async function disconnect() {
    if (statsTimer) window.clearInterval(statsTimer);
    statsTimer = null;
    if (room) await room.disconnect();
    for (const entry of tracks.values()) {
      entry.track.detach(entry.element);
      entry.element.remove();
    }
    tracks.clear();
    room = null;
    setConnected(false);
  }

  elements.connect.addEventListener('click', connect);
  elements.disconnect.addEventListener('click', disconnect);
  elements.enableAudio.addEventListener('click', async () => {
    if (!room) return;
    await room.startAudio();
    for (const { element } of tracks.values()) await element.play();
    elements.enableAudio.hidden = true;
  });
  window.setInterval(updateMinutes, 1000);
  window.addEventListener('beforeunload', () => room?.disconnect());
})();
