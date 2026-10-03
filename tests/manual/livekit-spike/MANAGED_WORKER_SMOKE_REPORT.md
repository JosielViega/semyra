# Managed IPTV worker smoke — 10B.3C

- Date: 2026-10-03 (America/Sao_Paulo)
- Baseline: `230aa02c4468abdb7212e838716a4ba54dc2b565`
- Outcome: failed before the Semyra viewer could connect; the smoke was not repeated
- Docker image: `livekit/gstreamer:1.22.8-prod-rs`
- Expected image digest: `sha256:0d9663ca1b0c13b752558b241494a7df61c6e1775ec37b455c76e3eb43b8e4f1`

## Managed flow observed

- Precheck: passed (Docker daemon, pinned image/digest, and required GStreamer plugins)
- Residual resources before smoke: zero bridge containers, zero active bridge jobs, zero IPTV/live transmissions, and zero owned Ingresses in the fixture context
- Fixture: one local IPTV/live transmission and one pending bridge job
- Initial job state: `pending`, desired state `running`, attempt count 0
- Worker mode: exactly one `--once`
- Claim: succeeded
- Attempt count: 1
- Maximum simultaneous owned Ingresses: 1
- Provider connection: opened by the managed feeder; no endpoint or provider identifier was recorded
- MPEG-TS: validated by the managed initial-buffer gate before the worker entered `running`
- Initial MPEG-TS buffer: reused by the same feeder connection
- Video path: H.264 passthrough; no video reencode
- Audio path: AAC decode/transcode to Opus
- LiveKit bypass transcoding: yes
- Video codec observed from Ingress state: `video/H264`
- Audio codec observed from Ingress state: `audio/opus`
- Resolution observed: 1920x1080
- FPS: not observed
- Ingress state during both safe inspections: `inactive`
- Published tracks during both safe inspections: 0
- Starting heartbeat/keep: observed
- Running heartbeat/keep: at least two renewal cycles observed; an exact response count was not instrumented

## Viewer result

- Semyra viewer connected: no
- Reason: the local HTTP server had already stopped when the single viewer attempted to connect
- Video track technically received: no
- Audio track technically received: no
- Human image confirmation: no
- Human audio confirmation: no
- Participant-minutes attributable with precision: not measurable in isolation with precision

## Failure and cleanup

- The worker process exited successfully from its CLI perspective and removed its feeder/container, but the job remained `running` with its lease and Ingress reference still present.
- No `action=stop` response was observed during the media run.
- The fixture then requested an instance-aware stop.
- After lease expiry, one cleanup-only reconciliation was executed with `--dry-run`; it did not claim a job, open the provider, start media, or create another Ingress.
- Final job state: desired state `stopped`, status `stopped`, attempt count 1, lease cleared, Ingress reference cleared, and no error code.
- Owned Ingresses after cleanup: 0
- Bridge containers after cleanup: 0 running / 0 stopped
- Residual worker, media-helper, media-watchdog, feeder, or GStreamer processes: 0
- Transmission fixture removed with instance-aware CAS: yes
- Runtime lock/watchdog/ready metadata after cleanup: none expected; verified separately during final audit
- Media dumps: 0
- Approximate managed media duration: about 90 seconds; this was not an endurance test

## Recommendation

Do not repeat the real smoke until a separate investigation explains why the worker ended while the control-plane job and Ingress remained active and why the Ingress never progressed from `inactive` to published tracks. Preserve this report as the evidence for that investigation; do not modify production behavior inside the validation step.

## Post-smoke investigation

- The managed GStreamer graph is functionally identical to the previously successful private spike: the same `fdsrc`, queue, `tsdemux`, H.264 RTP passthrough branch, AAC-to-Opus branch, RTP caps, sink pads, and WHIP sink endpoint are used. The pipeline was not changed.
- The production gateway and spike helper construct the WHIP endpoint identically: trimmed base URL, one slash, and a URL-encoded stream key.
- The `ready` marker is written after the initial MPEG-TS buffer validates the source. The worker then reports `running`; this means **source MPEG-TS ready**, not **LiveKit Ingress publishing**.
- The control-plane endpoints and Semyra viewer page were served by the same local PHP server process.
- The temporary smoke supervisor explicitly stopped that server in its cleanup block after the `--once` worker process returned. There was no deliberate server timeout.
- Therefore, server shutdown is confirmed to have occurred after worker termination. It explains why the later viewer connection was refused, but it does not explain why the worker terminated.
- Because the server was intended to remain alive until the worker returned, server loss is not sufficient evidence for either heartbeat unavailability or the missing final report. The exact worker termination cause remains indeterminate from the preserved evidence.
- The original media helper collapsed source-process and Docker/GStreamer termination into one generic exit. That diagnostic gap prevented classification as source failure versus pipeline/WHIP failure.
- Local-only observability now records one allowlisted exit enum and maps it to existing sanitized error codes. Raw subprocess stderr remains drained but is never printed or persisted.
- A test-only Ingress transition observer is prepared for a future authorized smoke. It can emit only relative time, allowlisted state, H264/Opus, dimensions, and FPS; it has not been executed against LiveKit Cloud.
- A dedicated server lifecycle harness is prepared with a known PID, `/health` checks, and explicit shutdown. It is not tied to worker completion and has no automatic runtime timeout.
- Any future retry must pass health checks before the worker, during media, before the viewer, and before stop. A health failure must abort the flow and trigger scoped cleanup instead of waiting for the viewer.
- Root-cause classification after this investigation: **still indeterminate**. A single future authorized smoke with the new local exit enum, Ingress transition observer, and independent server lifecycle is required to distinguish source exit, pipeline exit, lease loss, or another sanitized control failure.
