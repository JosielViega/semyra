using System.IO;

namespace Semyra.Desktop.Media;

internal interface IMediaPipeline : IAsyncDisposable
{
    Task StartAsync(ReadOnlyMemory<byte> initialBuffer, CancellationToken cancellationToken);
    Task PumpAsync(Stream providerStream, CancellationToken cancellationToken);
    Task StopAsync();
}

internal interface IMediaPipelineFactory
{
    IMediaPipeline CreatePublish(Uri whipEndpoint);
    IVodMediaPipeline CreateVodPublish(Uri localSource, Uri whipEndpoint) => throw new MediaEngineException("vod_publish_unavailable");
}

internal interface IVodMediaPipeline : IAsyncDisposable
{
    Task StartAsync(CancellationToken cancellationToken);
    Task WaitForExitAsync(CancellationToken cancellationToken);
    Task StopAsync();
    Task<VodPublisherSnapshot> PauseAsync(CancellationToken cancellationToken);
    Task<VodPublisherSnapshot> PlayAsync(CancellationToken cancellationToken);
    Task<VodPublisherSnapshot> SeekAsync(long positionMs, CancellationToken cancellationToken);
    Task<VodPublisherSnapshot> SnapshotAsync(CancellationToken cancellationToken);
}

internal sealed record VodPublisherSnapshot(string State, long PositionMs, long DurationMs, bool Seekable, string SessionId);

internal enum LivePublishVideoMode { Transcode, Passthrough }

internal static class LivePublishVideoModeResolver
{
    internal static LivePublishVideoMode Parse(string? value) => value?.Trim().ToLowerInvariant() switch
    {
        "passthrough" => LivePublishVideoMode.Passthrough,
        "transcode" or null or "" => LivePublishVideoMode.Transcode,
        _ => LivePublishVideoMode.Transcode,
    };

    internal static LivePublishVideoMode Resolve()
    {
#if DEBUG
        return Parse(Environment.GetEnvironmentVariable("SEMYRA_LIVE_PUBLISH_VIDEO_MODE"));
#else
        return LivePublishVideoMode.Transcode;
#endif
    }
}

internal enum VodPublishVideoMode { Transcode, Passthrough }

internal static class VodPublishVideoModeResolver
{
    internal static VodPublishVideoMode Parse(string? value) => value?.Trim().ToLowerInvariant() switch
    {
        "passthrough" => VodPublishVideoMode.Passthrough,
        "transcode" or null or "" => VodPublishVideoMode.Transcode,
        _ => VodPublishVideoMode.Transcode,
    };

    internal static VodPublishVideoMode Resolve()
    {
#if DEBUG
        return Parse(Environment.GetEnvironmentVariable("SEMYRA_VOD_PUBLISH_VIDEO_MODE"));
#else
        return VodPublishVideoMode.Transcode;
#endif
    }
}

internal sealed class MediaPipelineFactory : IMediaPipelineFactory
{
    private readonly GStreamerRuntime _runtime;
    private readonly LivePublishVideoMode _liveVideoMode;
    private readonly VodPublishVideoMode _vodVideoMode;

    internal MediaPipelineFactory(GStreamerRuntime runtime, VodPublishVideoMode? vodVideoMode = null, LivePublishVideoMode? liveVideoMode = null)
    {
        _runtime = runtime;
        _vodVideoMode = vodVideoMode ?? VodPublishVideoModeResolver.Resolve();
        _liveVideoMode = liveVideoMode ?? LivePublishVideoModeResolver.Resolve();
    }

    public IMediaPipeline CreatePublish(Uri whipEndpoint)
    {
        if (!_runtime.IsWhipAvailable || _runtime.LaunchPath is null || whipEndpoint.Scheme != Uri.UriSchemeHttps)
        {
            throw new MediaEngineException("whip_runtime_unavailable");
        }
        var arguments = _liveVideoMode == LivePublishVideoMode.Passthrough
            ? MediaPipeline.LivePublishPassthroughArguments(whipEndpoint)
            : MediaPipeline.PublishArguments(whipEndpoint);
        return new GStreamerProcess(_runtime.LaunchPath, arguments);
    }

    public IVodMediaPipeline CreateVodPublish(Uri localSource, Uri whipEndpoint)
    {
        if (!_runtime.IsVodPublishAvailable || _runtime.LaunchPath is null
            || !localSource.IsLoopback || localSource.Scheme != Uri.UriSchemeHttp
            || whipEndpoint.Scheme != Uri.UriSchemeHttps)
            throw new MediaEngineException("vod_publish_unavailable");
        if (_vodVideoMode == VodPublishVideoMode.Passthrough)
            return new GStreamerPullProcess(_runtime.LaunchPath, MediaPipeline.VodPublishPassthroughArguments(localSource, whipEndpoint));
        return new MediaHostVodPipeline(_runtime, localSource, whipEndpoint);
    }

}

internal static class MediaPipeline
{
    internal static IReadOnlyList<string> PublishArguments(Uri endpoint) =>
    [
        "-e",
        "fdsrc", "fd=0", "!", "queue", "max-size-time=3000000000", "!", "tsdemux", "name=demux",
        "whipsink", "name=whip", $"whip-endpoint={endpoint.AbsoluteUri}",
        "demux.", "!", "queue", "max-size-time=3000000000", "!", "h264parse", "!", "avdec_h264", "!", "videoconvert", "!",
        "x264enc", "bitrate=3500", "speed-preset=veryfast", "tune=zerolatency", "bframes=0", "key-int-max=48", "cabac=false", "dct8x8=false", "byte-stream=true", "!",
        "h264parse", "config-interval=-1", "!", "video/x-h264,stream-format=byte-stream,alignment=au,profile=constrained-baseline", "!",
        "rtph264pay", "config-interval=-1", "aggregate-mode=zero-latency", "pt=97", "!",
        "application/x-rtp,media=video,encoding-name=H264,payload=97,clock-rate=90000,packetization-mode=(string)1", "!", "whip.sink_0",
        "demux.", "!", "queue", "!", "aacparse", "!", "avdec_aac", "!", "audioconvert", "!", "audioresample", "!",
        "audio/x-raw,rate=48000,channels=2", "!", "opusenc", "bitrate=96000", "!", "rtpopuspay", "pt=96", "!",
        "application/x-rtp,media=audio,encoding-name=OPUS,payload=96,clock-rate=48000,encoding-params=(string)2", "!", "whip.sink_1",
    ];

    internal static IReadOnlyList<string> LivePublishPassthroughArguments(Uri endpoint) =>
    [
        "-e",
        "fdsrc", "fd=0", "!", "queue", "max-size-time=3000000000", "!", "tsdemux", "name=demux",
        "whipsink", "name=whip", $"whip-endpoint={endpoint.AbsoluteUri}",
        "demux.", "!", "queue", "!", "h264parse", "config-interval=-1", "!",
        "rtph264pay", "config-interval=1", "aggregate-mode=zero-latency", "pt=97", "!",
        "application/x-rtp,media=video,encoding-name=H264,payload=97,clock-rate=90000,packetization-mode=(string)1", "!", "whip.sink_0",
        "demux.", "!", "queue", "!", "aacparse", "!", "avdec_aac", "!", "audioconvert", "!", "audioresample", "!",
        "audio/x-raw,rate=48000,channels=2", "!", "opusenc", "bitrate=96000", "!", "rtpopuspay", "pt=96", "!",
        "application/x-rtp,media=audio,encoding-name=OPUS,payload=96,clock-rate=48000,encoding-params=(string)2", "!", "whip.sink_1",
    ];

    internal static IReadOnlyList<string> VodPublishArguments(Uri localSource, Uri endpoint) =>
    [
        "-e",
        "souphttpsrc", $"location={localSource.AbsoluteUri}", "!", "queue", "max-size-time=3000000000", "!", "qtdemux", "name=demux",
        "whipsink", "name=whip", $"whip-endpoint={endpoint.AbsoluteUri}",
        "demux.", "!", "queue", "max-size-time=3000000000", "!", "h264parse", "!", "avdec_h264", "!", "videoconvert", "!",
        "x264enc", "bitrate=2800", "speed-preset=veryfast", "tune=zerolatency", "bframes=0", "key-int-max=48", "cabac=false", "dct8x8=false", "byte-stream=true", "!",
        "h264parse", "config-interval=-1", "!", "video/x-h264,stream-format=byte-stream,alignment=au,profile=constrained-baseline", "!", "clocksync", "!",
        "rtph264pay", "config-interval=-1", "aggregate-mode=zero-latency", "pt=97", "!",
        "application/x-rtp,media=video,encoding-name=H264,payload=97,clock-rate=90000,packetization-mode=(string)1", "!", "whip.sink_0",
        "demux.", "!", "queue", "!", "aacparse", "!", "avdec_aac", "!", "audioconvert", "!", "audioresample", "!",
        "audio/x-raw,rate=48000,channels=2", "!", "opusenc", "bitrate=96000", "!", "clocksync", "!", "rtpopuspay", "pt=96", "!",
        "application/x-rtp,media=audio,encoding-name=OPUS,payload=96,clock-rate=48000,encoding-params=(string)2", "!", "whip.sink_1",
    ];

    internal static IReadOnlyList<string> VodPublishPassthroughArguments(Uri localSource, Uri endpoint) =>
    [
        "-e",
        "souphttpsrc", $"location={localSource.AbsoluteUri}", "!", "queue", "max-size-time=3000000000", "!", "qtdemux", "name=demux",
        "whipsink", "name=whip", $"whip-endpoint={endpoint.AbsoluteUri}",
        "demux.", "!", "queue", "!", "h264parse", "config-interval=-1", "!", "clocksync", "!",
        "rtph264pay", "config-interval=1", "aggregate-mode=zero-latency", "pt=97", "!",
        "application/x-rtp,media=video,encoding-name=H264,payload=97,clock-rate=90000,packetization-mode=(string)1", "!", "whip.sink_0",
        "demux.", "!", "queue", "!", "aacparse", "!", "avdec_aac", "!", "audioconvert", "!", "audioresample", "!",
        "audio/x-raw,rate=48000,channels=2", "!", "opusenc", "bitrate=96000", "!", "clocksync", "!", "rtpopuspay", "pt=96", "!",
        "application/x-rtp,media=audio,encoding-name=OPUS,payload=96,clock-rate=48000,encoding-params=(string)2", "!", "whip.sink_1",
    ];

}
