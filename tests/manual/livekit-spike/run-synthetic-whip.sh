#!/bin/sh
set -eu

test -n "${WHIP_ENDPOINT:-}"

exec gst-launch-1.0 -e \
  audiotestsrc is-live=true wave=sine ! audioconvert ! audioresample ! opusenc ! rtpopuspay ! \
    'application/x-rtp,media=audio,encoding-name=OPUS,payload=96,clock-rate=48000,encoding-params=(string)2' ! whip.sink_0 \
  videotestsrc is-live=true pattern=ball ! video/x-raw,width=1280,height=720,framerate=30/1 ! \
    x264enc speed-preset=3 tune=zerolatency bitrate=2500 key-int-max=60 ! h264parse ! rtph264pay config-interval=1 pt=97 ! \
    'application/x-rtp,media=video,encoding-name=H264,payload=97,clock-rate=90000' ! whip.sink_1 \
  whipsink name=whip whip-endpoint="$WHIP_ENDPOINT"
