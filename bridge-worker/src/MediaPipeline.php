<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class MediaPipeline
{
    public const GSTREAMER = <<<'SH'
exec gst-launch-1.0 -e fdsrc fd=0 ! queue max-size-time=3000000000 ! tsdemux name=demux demux. ! queue ! h264parse config-interval=-1 ! rtph264pay config-interval=1 aggregate-mode=zero-latency pt=97 ! 'application/x-rtp,media=video,encoding-name=H264,payload=97,clock-rate=90000,packetization-mode=(string)1' ! whip.sink_0 demux. ! queue ! aacparse ! avdec_aac ! audioconvert ! audioresample ! audio/x-raw,rate=48000,channels=2 ! opusenc bitrate=96000 ! rtpopuspay pt=96 ! 'application/x-rtp,media=audio,encoding-name=OPUS,payload=96,clock-rate=48000,encoding-params=(string)2' ! whip.sink_1 whipsink name=whip whip-endpoint="$WHIP_ENDPOINT"
SH;
}
