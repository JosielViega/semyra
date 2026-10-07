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
}
