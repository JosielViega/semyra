# LIVEKIT IPTV WHIP SPIKE

## Synthetic WHIP

- Ingress created: yes
- Input: WHIP
- Transcoding enabled: no
- Video: H264, 1280×720, approximately 30 fps
- Audio: Opus
- Browser playback: video visible; video and audio tracks subscribed
- Track removal after bridge stop: observed
- Ingress reusable after stop: yes

## Real IPTV

- Authorized local candidate: selected from private allowlist
- Source container: MPEG-TS
- Source video: H264
- Source audio: AAC

## Bridge

- Video strategy: passthrough and RTP repacketization
- Video passthrough: success
- Local video transcode: not required
- Audio strategy: AAC → raw audio → Opus
- Intermediate media files: none
- Approximate point-in-time resources: 5.25% CPU, 25.57 MiB memory
- Qualitative CPU: low

## Ingress

- Input: WHIP
- LiveKit transcode: no
- Bridge start → ingress publishing: observed within approximately 13 seconds
- Ingress publishing → first observed subscribed video: within approximately 4 seconds

## Viewer A

- Video: human-confirmed yes
- Audio: human-confirmed yes
- Video codec: H264
- Audio codec: Opus
- Resolution: 1920×1080
- FPS: approximately 30
- Connection quality: excellent during observation

## Viewer B

- Video: human-confirmed yes
- Audio: human-confirmed yes
- Video codec: H264
- Audio codec: Opus
- Resolution: 1920×1080
- FPS: approximately 30
- Connection quality: excellent during observation

## Observation

- Same channel visible on both viewers: yes
- Apparent A/B sync: good
- Stable dual-viewer interval: at least approximately 60 seconds including human validation
- Approximate inbound video bitrate observed: 4.08–5.30 Mbps
- Approximate transfer per viewer-hour: 1.84–2.39 GB
- Approximate additional participant usage: about 15–16 participant-minutes including synthetic validation, diagnostic idle time, and the controlled stability repeat
- Real-stream wall-clock exposure: approximately 2–3 minutes across the validation and stability runs
- Approximate real-media participant usage: about 5–6 participant-minutes
- LiveKit ingress transcode expected: 0 minutes
- Billing dashboard confirmation: not performed

## Security and cleanup

- Provider credential exposure: none detected
- Provider URL/host exposure: none detected
- LiveKit credential exposure: none detected
- WHIP endpoint/credential exposure: none detected
- Media dump or persistent bridge log: none
- Temporary ingress deleted: yes
- Ephemeral WHIP files removed: yes
- Bridge and viewer server stopped: yes

## Conclusion

The experimental architecture is approved: authorized continuous MPEG-TS can be consumed locally, H.264 can pass through without video reencode, AAC can be converted locally to Opus, and H264/Opus can reach two LiveKit viewers over WHIP with LiveKit transcoding disabled. This result does not start or define production Etapa 10B.
