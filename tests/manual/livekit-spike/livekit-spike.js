(() => {
  'use strict';

  const LK = window.LivekitClient;
  const Diagnostics = window.LiveKitSpikeDiagnostics;
  const elements = {
    status: document.querySelector('#connection-status'),
    identity: document.querySelector('#identity'),
    participantCount: document.querySelector('#participant-count'),
    microphoneStatus: document.querySelector('#microphone-status'),
    publishedCount: document.querySelector('#published-count'),
    subscribedCount: document.querySelector('#subscribed-count'),
    participantMinutes: document.querySelector('#participant-minutes'),
    connect: document.querySelector('#connect'),
    disconnect: document.querySelector('#disconnect'),
    reconnect: document.querySelector('#reconnect'),
    microphone: document.querySelector('#microphone'),
    enableAudio: document.querySelector('#enable-audio'),
    events: document.querySelector('#events'),
    remoteAudio: document.querySelector('#remote-audio'),
    diagnosticsSamples: document.querySelector('#diagnostics-samples'),
    safeTransport: document.querySelector('#safe-transport'),
    validationBlock: document.querySelector('#validation-block'),
  };

  let room = null;
  let connectedAt = null;
  let connectedMilliseconds = 0;
  let durationTimer = null;
  const audioElements = new Map();
  const diagnosticsSamples = [];
  const diagnosticsTimers = [];
  let diagnosticsStartedAt = null;
  let autoplayResult = 'automatic';

  function qualityOf(participant) {
    return Diagnostics.normalizeQuality(participant?.connectionQuality, LK.ConnectionQuality);
  }

  function latestSample() {
    return diagnosticsSamples.at(-1) ?? null;
  }

  function renderValidationBlock() {
    const sample = latestSample();
    const sender = sample?.sender ?? {};
    const receiver = sample?.receiver ?? {};
    elements.validationBlock.value = [
      'LIVEKIT VOICE VALIDATION',
      '',
      'Local -> remote:',
      'Audible: human confirmation required',
      'Quality: human confirmation required',
      `RTT: ${Diagnostics.formatMilliseconds(sender.roundTripTimeMs)}`,
      `Jitter: ${Diagnostics.formatMilliseconds(sender.jitterMs)}`,
      `Packet loss: ${Diagnostics.formatLoss(sender.packetsLost, sender.lossPercent)}`,
      'Jitter buffer: unavailable at sender',
      `Connection quality: ${sample?.localQuality ?? 'unknown'}`,
      '',
      'Remote -> local:',
      'Audible: human confirmation required',
      'Quality: human confirmation required',
      'RTT: unavailable at receiver',
      `Jitter: ${Diagnostics.formatMilliseconds(receiver.jitterMs)}`,
      `Packet loss: ${Diagnostics.formatLoss(receiver.packetsLost, receiver.lossPercent)}`,
      `Jitter buffer: ${Diagnostics.formatMilliseconds(receiver.jitterBufferMs)}`,
      `Connection quality: ${sample?.remoteQuality ?? 'unknown'}`,
      '',
      'Perceived latency: human confirmation required',
      `Autoplay: ${autoplayResult}`,
      'LiveKit media region: not directly exposed by current spike',
    ].join('\n');
  }

  function renderDiagnostics() {
    elements.diagnosticsSamples.replaceChildren();
    if (diagnosticsSamples.length === 0) {
      const row = elements.diagnosticsSamples.insertRow();
      const cell = row.insertCell();
      cell.colSpan = 7;
      cell.textContent = diagnosticsStartedAt ? 'Aguardando primeira amostra (t≈10 s).' : 'Aguardando áudio bidirecional.';
    }
    for (const sample of diagnosticsSamples) {
      const outgoing = elements.diagnosticsSamples.insertRow();
      [
        `t≈${sample.elapsedSeconds} s`,
        'local → remote',
        Diagnostics.formatMilliseconds(sample.sender.roundTripTimeMs),
        Diagnostics.formatMilliseconds(sample.sender.jitterMs),
        Diagnostics.formatLoss(sample.sender.packetsLost, sample.sender.lossPercent),
        'unavailable',
        sample.localQuality,
      ].forEach((value) => { outgoing.insertCell().textContent = value; });

      const incoming = elements.diagnosticsSamples.insertRow();
      [
        `t≈${sample.elapsedSeconds} s`,
        'remote → local',
        'unavailable',
        Diagnostics.formatMilliseconds(sample.receiver.jitterMs),
        Diagnostics.formatLoss(sample.receiver.packetsLost, sample.receiver.lossPercent),
        Diagnostics.formatMilliseconds(sample.receiver.jitterBufferMs),
        sample.remoteQuality,
      ].forEach((value) => { incoming.insertCell().textContent = value; });
    }
    const transport = latestSample()?.transport;
    elements.safeTransport.textContent = transport?.protocol && transport?.candidateType
      ? `${transport.protocol} / ${transport.candidateType}`
      : 'unavailable';
    renderValidationBlock();
  }

  function localAudioTrack() {
    if (!room) return null;
    for (const publication of room.localParticipant.audioTrackPublications.values()) {
      if (publication.track) return publication.track;
    }
    return null;
  }

  function safeStatsCall(track, method) {
    if (!track || typeof track[method] !== 'function') return Promise.resolve(undefined);
    return Promise.resolve(track[method]()).catch(() => undefined);
  }

  async function collectDiagnostics(elapsedSeconds) {
    const localTrack = localAudioTrack();
    const remoteEntry = audioElements.values().next().value;
    if (!localTrack || !remoteEntry) return;
    try {
      const [senderSummary, senderReport, receiverSummary, receiverReport] = await Promise.all([
        safeStatsCall(localTrack, 'getSenderStats'),
        safeStatsCall(localTrack, 'getRTCStatsReport'),
        safeStatsCall(remoteEntry.track, 'getReceiverStats'),
        safeStatsCall(remoteEntry.track, 'getRTCStatsReport'),
      ]);
      diagnosticsSamples.push({
        elapsedSeconds,
        sender: Diagnostics.extractSender(senderSummary, senderReport),
        receiver: Diagnostics.extractReceiver(receiverSummary, receiverReport),
        transport: Diagnostics.extractSafeTransport(senderReport ?? receiverReport),
        localQuality: qualityOf(room.localParticipant),
        remoteQuality: qualityOf(remoteEntry.participant),
      });
      renderDiagnostics();
      addEvent('diagnostics-sample', `t≈${elapsedSeconds}s`);
    } catch (_error) {
      addEvent('diagnostics-unavailable', `t≈${elapsedSeconds}s`);
    }
  }

  function maybeStartDiagnostics() {
    if (diagnosticsStartedAt || !isConnected() || !localAudioTrack() || audioElements.size === 0) return;
    diagnosticsStartedAt = Date.now();
    for (const seconds of [10, 30, 60]) {
      diagnosticsTimers.push(window.setTimeout(() => collectDiagnostics(seconds), seconds * 1000));
    }
    addEvent('diagnostics-started', '10s / 30s / 60s');
    renderDiagnostics();
  }

  function resetDiagnostics() {
    diagnosticsTimers.splice(0).forEach((timer) => window.clearTimeout(timer));
    diagnosticsSamples.splice(0);
    diagnosticsStartedAt = null;
    autoplayResult = 'automatic';
    renderDiagnostics();
  }

  function addEvent(type, detail = '') {
    const item = document.createElement('li');
    item.textContent = detail ? `${type}: ${detail}` : type;
    elements.events.prepend(item);
    while (elements.events.children.length > 40) {
      elements.events.lastElementChild.remove();
    }
  }

  function abbreviatedIdentity(identity) {
    if (!identity || typeof identity !== 'string') return '—';
    const suffix = identity.slice(-6);
    return `lkspike_…${suffix}`;
  }

  function isConnected() {
    return room && room.state === LK.ConnectionState.Connected;
  }

  function publicationCount(collection) {
    if (!collection) return 0;
    if (typeof collection.size === 'number') return collection.size;
    return Array.from(collection).length;
  }

  function updateDuration() {
    const current = connectedAt ? Date.now() - connectedAt : 0;
    const minutes = (connectedMilliseconds + current) / 60000;
    elements.participantMinutes.textContent = `${minutes.toFixed(2).replace('.', ',')} min`;
  }

  function updateMetrics() {
    const connected = isConnected();
    elements.participantCount.textContent = connected ? String(room.remoteParticipants.size + 1) : '0';
    elements.publishedCount.textContent = connected
      ? String(publicationCount(room.localParticipant.audioTrackPublications))
      : '0';
    elements.subscribedCount.textContent = String(audioElements.size);
    elements.connect.disabled = connected;
    elements.disconnect.disabled = !connected;
    elements.reconnect.disabled = !connected;
    elements.microphone.disabled = !connected;
    updateDuration();
  }

  function setConnectionStatus(status) {
    elements.status.textContent = status;
    updateMetrics();
  }

  function removeRemoteAudio(trackSid) {
    const entry = audioElements.get(trackSid);
    if (!entry) return;
    entry.track.detach(entry.element);
    entry.element.remove();
    audioElements.delete(trackSid);
    updateMetrics();
  }

  function attachRemoteAudio(track, publication, participant) {
    if (track.kind !== LK.Track.Kind.Audio || audioElements.has(publication.trackSid)) return;
    const element = track.attach();
    element.autoplay = true;
    element.playsInline = true;
    elements.remoteAudio.append(element);
    audioElements.set(publication.trackSid, { track, element, participant });
    element.play().catch(() => {
      autoplayResult = 'required-user-action';
      elements.enableAudio.hidden = false;
      addEvent('autoplay-blocked', 'ação do usuário necessária');
      renderValidationBlock();
    });
    updateMetrics();
    maybeStartDiagnostics();
  }

  function bindRoomEvents(targetRoom) {
    const E = LK.RoomEvent;
    targetRoom
      .on(E.Connected, () => {
        connectedAt = Date.now();
        setConnectionStatus('Conectado');
        elements.identity.textContent = abbreviatedIdentity(targetRoom.localParticipant.identity);
        addEvent('connected');
      })
      .on(E.Disconnected, () => {
        if (connectedAt) connectedMilliseconds += Date.now() - connectedAt;
        connectedAt = null;
        setConnectionStatus('Desconectado');
        elements.identity.textContent = '—';
        elements.microphoneStatus.textContent = 'Desligado';
        elements.microphone.dataset.enabled = 'false';
        elements.microphone.textContent = 'Ativar microfone';
        addEvent('disconnected');
      })
      .on(E.ParticipantConnected, () => {
        addEvent('participant-connected');
        updateMetrics();
      })
      .on(E.ParticipantDisconnected, (participant) => {
        for (const publication of participant.audioTrackPublications.values()) {
          removeRemoteAudio(publication.trackSid);
        }
        addEvent('participant-disconnected');
        updateMetrics();
      })
      .on(E.TrackSubscribed, (track, publication, participant) => {
        attachRemoteAudio(track, publication, participant);
        addEvent('track-subscribed', track.kind);
      })
      .on(E.TrackUnsubscribed, (track, publication) => {
        removeRemoteAudio(publication.trackSid);
        addEvent('track-unsubscribed', track.kind);
      })
      .on(E.TrackMuted, (publication) => addEvent('track-muted', publication.kind))
      .on(E.TrackUnmuted, (publication) => addEvent('track-unmuted', publication.kind))
      .on(E.ConnectionQualityChanged, (quality, participant) => {
        addEvent('connection-quality', Diagnostics.normalizeQuality(quality, LK.ConnectionQuality));
        if (participant) renderValidationBlock();
      })
      .on(E.Reconnecting, () => {
        setConnectionStatus('Reconectando');
        addEvent('reconnecting');
      })
      .on(E.Reconnected, () => {
        setConnectionStatus('Conectado');
        addEvent('reconnected');
      });

    if (E.AudioPlaybackStatusChanged) {
      targetRoom.on(E.AudioPlaybackStatusChanged, () => {
        elements.enableAudio.hidden = targetRoom.canPlaybackAudio;
        addEvent('audio-playback-status', targetRoom.canPlaybackAudio ? 'allowed' : 'blocked');
      });
    }
  }

  async function requestConnectionDetails() {
    const response = await fetch('/token.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: '{}',
    });
    if (!response.ok) throw new Error(`token-endpoint-${response.status}`);
    const details = await response.json();
    if (typeof details.server_url !== 'string' || typeof details.participant_token !== 'string') {
      throw new Error('invalid-token-response');
    }
    return details;
  }

  async function connect() {
    if (isConnected()) return;
    resetDiagnostics();
    setConnectionStatus('Conectando');
    elements.connect.disabled = true;
    try {
      let details = await requestConnectionDetails();
      room = new LK.Room({ adaptiveStream: true, dynacast: true });
      bindRoomEvents(room);
      await room.connect(details.server_url, details.participant_token, { autoSubscribe: true });
      details = null;
      updateMetrics();
    } catch (_error) {
      setConnectionStatus('Falha ao conectar');
      elements.connect.disabled = false;
      addEvent('connection-failed');
    }
  }

  async function disconnect() {
    if (!room) return;
    await room.disconnect();
    for (const trackSid of Array.from(audioElements.keys())) removeRemoteAudio(trackSid);
    room = null;
    updateMetrics();
  }

  async function toggleMicrophone() {
    if (!isConnected()) return;
    elements.microphone.disabled = true;
    try {
      const enabled = elements.microphone.dataset.enabled !== 'true';
      await room.localParticipant.setMicrophoneEnabled(enabled);
      elements.microphone.dataset.enabled = String(enabled);
      elements.microphone.textContent = enabled ? 'Mutar microfone' : 'Ativar microfone';
      elements.microphoneStatus.textContent = enabled ? 'Ligado' : 'Mutado';
      addEvent(enabled ? 'microphone-enabled' : 'microphone-muted');
      maybeStartDiagnostics();
    } catch (_error) {
      elements.microphoneStatus.textContent = 'Indisponível';
      addEvent('microphone-unavailable');
    } finally {
      elements.microphone.disabled = false;
      updateMetrics();
    }
  }

  async function enableAudio() {
    if (!room) return;
    try {
      await room.startAudio();
      for (const { element } of audioElements.values()) await element.play();
      elements.enableAudio.hidden = true;
      addEvent('audio-enabled');
    } catch (_error) {
      addEvent('audio-enable-failed');
    }
  }

  elements.connect.addEventListener('click', connect);
  elements.disconnect.addEventListener('click', disconnect);
  elements.reconnect.addEventListener('click', async () => {
    addEvent('basic-reconnect-started');
    await disconnect();
    await connect();
  });
  elements.microphone.addEventListener('click', toggleMicrophone);
  elements.enableAudio.addEventListener('click', enableAudio);
  window.addEventListener('beforeunload', () => room?.disconnect());

  durationTimer = window.setInterval(updateDuration, 1000);
  window.addEventListener('unload', () => window.clearInterval(durationTimer));
  updateMetrics();
  renderDiagnostics();
})();
