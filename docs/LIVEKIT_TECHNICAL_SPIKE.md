# LiveKit Technical Spike

## Goal

Consolidate the isolated validation of two-party voice and the experimental IPTV MPEG-TS → WHIP bridge using LiveKit Cloud with the current PHP, Composer, vanilla JavaScript, and browser stack. This stage does not integrate LiveKit into Semyra rooms.

## Why LiveKit

LiveKit supplies managed signaling, WebRTC transport, participant lifecycle, track publication/subscription, and reconnect behavior. The spike tests whether those primitives can be consumed without adding a Node runtime or frontend framework to Semyra.

## Current Semyra architecture

The production application remains unchanged. The experiment lives under `tests/manual/livekit-spike/`, uses its own Composer project and vendored browser bundle, and is excluded from the HostGator deployment mirror through the existing tests exclusion.

## Cloud Build plan

No cloud build is required. PHP signs participant tokens locally and the browser loads the vendored UMD SDK. LiveKit Cloud is contacted only during an explicitly initiated manual test with local private configuration.

## Token architecture

`POST /token.php` accepts an empty JSON object. The backend fixes the room, generates an opaque participant identity, and issues a 600-second token with `roomJoin`, `canPublish`, and `canSubscribe`. Publishing is restricted to the microphone; data publishing and room administration are not granted. Responses use `Cache-Control: no-store`.

The API secret stays in `.private/livekit.env` and PHP memory. The API key is not returned as an explicit field, although the LiveKit JWT standard includes it as the signed issuer. Participant tokens are not logged, decoded by the UI, placed in URLs, or persisted in browser storage.

## Browser client

The browser uses `livekit-client` 2.22.3 as a local UMD bundle. It connects only after a user click, asks for microphone permission only when the microphone button is used, never enables the camera, attaches subscribed remote audio to `HTMLAudioElement`, and exposes an explicit autoplay recovery button.

## Voice test

The intended manual validation uses two tabs or browsers, preferably with headphones. Both participants must connect, publish microphone audio, receive the opposite remote audio, mute/unmute, observe join/leave, disconnect, and perform one basic reconnect. Technical events do not substitute for human confirmation that audio was audible.

The local run connected two opaque participants to the fixed room. Both published one microphone track and subscribed to one remote audio track. Remote `track-muted` and `track-unmuted` events were observed in both directions. A observed B leaving and the remote track being unsubscribed; B then rejoined with a new opaque identity. Autoplay was allowed.

Human validation confirmed audible voice in both directions, successful mute/unmute, acceptable quality, low perceived latency, and no obvious audible problem. Sanitized 10/30/60-second samples showed approximately 17–22 ms RTT, 0.3–1.5 ms sender jitter, 2–3 ms receiver jitter, zero observed packet loss, and `excellent` connection quality. The media region was not directly exposed. Etapa 10A.4A is technically and functionally approved.

## Security boundaries

The local router serves an explicit allowlist and refuses access to `.private`, Composer `vendor`, and unrelated files. The token endpoint is POST-only, JSON-only, same-origin, non-cacheable, and does not accept client-selected room or identity. A production endpoint would additionally require Semyra session authorization, CSRF/origin controls, abuse protection, and room-specific policy.

## Cost observations

The real test should be short. Both clients must disconnect after validation, and approximate connected participant-minutes should be recorded from the local UI rather than requiring the Cloud dashboard.

The main two Chrome sessions recorded 13.10 and 12.69 participant-minutes. Including short preliminary browser sessions used to resolve microphone permission behavior, the experiment consumed approximately 28 connected participant-minutes.

## Voice scope

The successful voice run validates the realtime participant and audio foundation. By itself, it does not prove IPTV ingestion, media compatibility, synchronization, cost suitability, or production deployment design; those were investigated separately by the WHIP experiment below.

## Raw MPEG-TS limitation

LiveKit documents HTTP Ingress support for HLS, MP4, MOV, MKV/WebM, OGG, MP3, and M4A. The current provider source is continuous MPEG-TS over HTTP. Direct raw MPEG-TS HTTP Ingress is therefore not assumed to be supported.

## IPTV WHIP bridge

Etapa 10A.4B tested an isolated local bridge with the official `livekit/gstreamer:1.22.8-prod-rs` image. Direct URL Input was not selected because LiveKit documents HTTP/HLS and specific file containers but does not list continuous raw MPEG-TS. Sending the private provider URL to URL Input would also cross the intended credential boundary and enable Ingress transcoding.

The selected boundary keeps provider credentials and the source URL in a local ignored candidate map. A PHP feeder resolves only the allowlisted local candidate in memory and pipes MPEG-TS bytes to Docker stdin. LiveKit receives only WHIP media. No URL, provider host, credential, playlist, media dump, or persistent bridge log is created.

The official GStreamer image included WHIP, RTP H.264/Opus, MPEG-TS demux, H.264 parse, AAC decode, audio conversion, and Opus encode elements. A synthetic H.264/Opus test reached the browser at 1280×720/30 fps before the real source was attempted.

For the real authorized source, H.264 passthrough/repacketization succeeded at 1920×1080/30 fps; local video reencode was not required. AAC was decoded and encoded to Opus locally. Both subscribe-only viewers received H.264 video and Opus audio, human validation confirmed image/audio in both browsers and the same channel with good apparent A/B sync. LiveKit reported WHIP with transcoding disabled throughout.

Observed viewer video bitrate ranged approximately from 4.08 to 5.30 Mbps, equivalent to roughly 1.84–2.39 GB per viewer-hour. The passthrough bridge used 5.25% CPU and 25.57 MiB in one point sample. Snapshots showed zero packet loss. These are local spike observations, not capacity or billing guarantees. Absolute provider latency was not measurable without a source time marker.

```text
Provider HTTP MPEG-TS
    ↓
local/private bridge
    ├─ H.264 passthrough
    └─ AAC → Opus
    ↓ WHIP
LiveKit Cloud
    ↓ WebRTC
viewers
```

Provider credentials and the source URL remain at the bridge boundary. LiveKit receives processed media, while viewers receive WebRTC media only.

The bridge, viewers, local server, and temporary ingress were stopped after validation. Ephemeral WHIP endpoint data was removed. The detailed sanitized result is in `tests/manual/livekit-spike/IPTV_WHIP_REPORT.md`.

## Decision gate

Voice and the local MPEG-TS → WHIP architecture are experimentally validated. Production adoption still requires a separately scoped 10B design covering lifecycle, authorization, operations, cost, channel switching, failure recovery, and deployment outside shared HostGator PHP.
