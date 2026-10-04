# Semyra bridge worker on Linux

This directory describes a future installation on a dedicated Linux VPS. The local builder does not connect to a host, install software, upload files, or start media.

## Target layout and ownership

```text
/opt/semyra-bridge/
    releases/<release-id>/    root:root, read-only to the service user
    current -> releases/<release-id>
/etc/semyra/
    bridge-worker.env         root:root, 0600
    bridge-sources.json       root:semyra-bridge, 0640
/run/semyra-bridge/           semyra-bridge:semyra-bridge, 0700, ephemeral
/etc/systemd/system/
    semyra-bridge.service
```

The `semyra-bridge` account is a dedicated system user without an interactive login. The service never runs as root. For the initial dedicated-host MVP it receives `SupplementaryGroups=docker`; membership in the Docker group is effectively highly privileged on the host. Do not make `/var/run/docker.sock` world-writable and never use `chmod 666`. Rootless Docker may be evaluated later, but is not part of this package.

The private source catalog is never included in a release. It contains provider URLs and stays at `/etc/semyra/bridge-sources.json`. The environment file must not contain a provider URL. `SEMYRA_WORKER_ID` is stable per installation and follows `wrk_<32 lowercase hex>`; do not regenerate it on every restart. `SEMYRA_WORKER_SECRET` must match the control plane and must exist only in the private environment file—not in a CLI argument, unit, repository, release, or journal.

The systemd manager reads the root-owned `EnvironmentFile` before changing to `User=semyra-bridge`. The service only writes to `/run/semyra-bridge`; releases contain no runtime, logs, catalog, environment file, downloads, or cache. stdout and sanitized stderr go to the journal rather than a worker-owned log file.

## Future first installation

Run equivalent distribution-appropriate commands as root after reviewing them for the target host:

```sh
groupadd --system semyra-bridge
useradd --system --gid semyra-bridge --groups docker --home-dir /nonexistent --shell /usr/sbin/nologin semyra-bridge
install -d -o root -g root -m 0755 /opt/semyra-bridge /opt/semyra-bridge/releases
install -d -o root -g semyra-bridge -m 0750 /etc/semyra
install -o root -g root -m 0600 bridge-worker.env /etc/semyra/bridge-worker.env
install -o root -g semyra-bridge -m 0640 bridge-sources.json /etc/semyra/bridge-sources.json
install -o root -g root -m 0644 semyra-bridge.service /etc/systemd/system/semyra-bridge.service
```

Populate the two private configuration files out of band. Do not display them through `systemctl show`, shell tracing, or diagnostic bundles. Docker, PHP CLI, the pinned `livekit/gstreamer:1.22.8-prod-rs` image and its already-pinned digest are host prerequisites to be validated in a later stage.

## Build and immutable release

`composer build:bridge-worker` creates `deploy/bridge-worker/release/` locally from an explicit allowlist. Build is not remote deployment. `build-info.json` records the source SHA, UTC build time and whether the source tree was dirty; a dirty build is for local validation only and must not be published. Installed releases are immutable, root-owned, and read-only to the service user; directories should be `root:root 0755` and regular files `root:root 0644` because PHP reads the scripts directly.

## Future update

1. Build from an approved, clean commit and transfer the package to the host through the approved release channel.
2. Place the complete package at `/opt/semyra-bridge/releases/<new-sha>` and make it root-owned/read-only.
3. Do not modify `current` or replace files underneath a running worker.
4. Run `systemctl stop semyra-bridge` and wait for cooperative shutdown.
5. Create a temporary replacement symlink beside `current`, then atomically rename/move it over `/opt/semyra-bridge/current` on the same filesystem; never update `current` file by file or leave a gap without a target.
6. Review/install the unit and run `systemctl daemon-reload` only if the unit changed.
7. Run `systemctl start semyra-bridge`. `ExecStartPre` executes `--check` before `--loop`.
8. Confirm `systemctl is-active semyra-bridge`, then inspect `systemctl status semyra-bridge` and `journalctl -u semyra-bridge` without dumping environment or private catalog contents.

`KillMode=mixed` sends the initial SIGTERM only to the main PHP process. `ProcessSignalStopRequest` then lets `WorkerRunner` stop Docker/media, remove the watchdog, report `failed/worker_shutdown` best-effort, and exit. Remaining processes may receive SIGKILL only after `TimeoutStopSec`. There is no custom `ExecStop` and the unit never directly invokes `docker kill`. `Restart=on-failure` keeps an explicit `systemctl stop` stopped; a restart performs cooperative stop followed by a new claim/generation.

When `worker_shutdown` reaches the control plane, it does not consume `failure_count`. If the control plane is unavailable and the report cannot be delivered, the lease can later expire and is indistinguishable from a crash, consuming one failure. Avoid planned restarts during a known control-plane outage.

## Future rollback

1. Run `systemctl stop semyra-bridge` and wait for shutdown.
2. Atomically repoint `current` to the retained previous release.
3. Restore the previous unit if it changed and run `systemctl daemon-reload` when needed.
4. Start the service and verify active status and sanitized journal output.

Do not delete the previous release immediately after an update. `--check` validates configuration/runtime, Docker daemon, pinned image and digest, GStreamer/plugins, and source catalog syntax without opening the provider or claiming work.
