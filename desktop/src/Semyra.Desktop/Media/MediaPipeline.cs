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
    IMediaPipeline Create();
    IMediaPipeline CreatePublish(Uri whipEndpoint);
    IMediaPipeline CreateLocalView(string outputDirectory);
}

internal sealed class MediaPipelineFactory(GStreamerRuntime runtime) : IMediaPipelineFactory
{
    public IMediaPipeline Create()
    {
        if (!runtime.IsAvailable || runtime.LaunchPath is null)
        {
            throw new MediaEngineException("media_runtime_unavailable");
        }
        return new GStreamerProcess(runtime.LaunchPath, MediaPipeline.Arguments);
    }

    public IMediaPipeline CreatePublish(Uri whipEndpoint)
    {
        if (!runtime.IsWhipAvailable || runtime.LaunchPath is null || whipEndpoint.Scheme != Uri.UriSchemeHttps)
        {
            throw new MediaEngineException("whip_runtime_unavailable");
        }
        return new GStreamerProcess(runtime.LaunchPath, MediaPipeline.PublishArguments(whipEndpoint));
    }

    public IMediaPipeline CreateLocalView(string outputDirectory)
    {
        if (!runtime.IsLocalViewAvailable || runtime.LaunchPath is null)
        {
            throw new MediaEngineException("local_view_runtime_unavailable");
        }
        return new GStreamerProcess(runtime.LaunchPath, MediaPipeline.LocalViewArguments, outputDirectory);
    }
}

internal static class MediaPipeline
{
    internal static readonly IReadOnlyList<string> Arguments =
    [
        "-e",
        "fdsrc", "fd=0", "!", "queue", "max-size-time=3000000000", "!", "tsdemux", "name=demux",
        "demux.", "!", "queue", "!", "h264parse", "config-interval=-1", "!",
        "rtph264pay", "config-interval=1", "aggregate-mode=zero-latency", "pt=97", "!",
        "application/x-rtp,media=video,encoding-name=H264,payload=97,clock-rate=90000,packetization-mode=(string)1", "!",
        "fakesink", "sync=false",
        "demux.", "!", "queue", "!", "aacparse", "!", "avdec_aac", "!", "audioconvert", "!", "audioresample", "!",
        "audio/x-raw,rate=48000,channels=2", "!", "opusenc", "bitrate=96000", "!", "rtpopuspay", "pt=96", "!",
        "application/x-rtp,media=audio,encoding-name=OPUS,payload=96,clock-rate=48000,encoding-params=(string)2", "!",
        "fakesink", "sync=false",
    ];

    internal static IReadOnlyList<string> PublishArguments(Uri endpoint) =>
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

    internal static readonly IReadOnlyList<string> LocalViewArguments =
    [
        "-e",
        "fdsrc", "fd=0", "is-live=true", "!", "queue", "max-size-time=3000000000", "!", "tsdemux", "name=demux",
        "mpegtsmux", "name=mux", "!", "hlssink", "playlist-location=index.m3u8", "location=segment%05d.ts",
        "target-duration=2", "playlist-length=3", "max-files=6",
        "demux.", "!", "queue", "!", "h264parse", "config-interval=-1", "!",
        "video/x-h264,stream-format=byte-stream,alignment=au", "!", "mux.",
        "demux.", "!", "queue", "!", "aacparse", "!",
        "audio/mpeg,mpegversion=4,stream-format=adts", "!", "mux.",
    ];
}
