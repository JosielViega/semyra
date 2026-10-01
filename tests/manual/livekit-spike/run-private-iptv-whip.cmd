@echo off
php "%~dp0feed-private-iptv.php" LIVE_H264_1 | docker run --rm -i --name semyra-livekit-iptv --env-file "%~dp0.private\whip-gstreamer.env" -v "%~dp0.:/spike:ro" --entrypoint /bin/sh livekit/gstreamer:1.22.8-prod-rs /spike/run-private-iptv-whip.sh
