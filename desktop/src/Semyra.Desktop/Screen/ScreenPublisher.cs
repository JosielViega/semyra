using System.Diagnostics;
using Semyra.Desktop.Host;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Screen;

public sealed record ScreenPublisherSnapshot(string State, string? TargetId, string? TargetType, string? Quality,
    bool Audio, string? Encoder, string? ErrorCode, string? DiagnosticCode = null);
internal sealed class ScreenPublisherException(string code, Exception? inner = null) : Exception(code, inner) { internal string Code { get; } = code; }

internal sealed class ScreenPublisher : IAsyncDisposable
{
    private const int MaximumTransientStatusFailures = 8;
    private static readonly TimeSpan PublishingTimeout = TimeSpan.FromSeconds(18);
    private static readonly TimeSpan StatusRequestTimeout = TimeSpan.FromSeconds(3);
    private static readonly TimeSpan StatusPollInterval = TimeSpan.FromMilliseconds(400);
    private readonly GStreamerRuntime _runtime;
    private readonly IDesktopPublishClient _publishClient;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private Process? _process;
    private ScreenPipelineDiagnostics? _diagnostics;
    private DesktopPublishLease? _lease;
    private HostAuthorization? _authorization;
    private CancellationTokenSource? _startCancellation;
    private ScreenPublisherSnapshot _snapshot = new("idle", null, null, null, false, null, null);
    internal event EventHandler<ScreenPublisherSnapshot>? StateChanged;

    internal ScreenPublisher(GStreamerRuntime runtime, IDesktopPublishClient publishClient)
    { _runtime = runtime; _publishClient = publishClient; }

    internal ScreenPublisherSnapshot Snapshot() => _snapshot;

    internal bool RefreshAuthorization(HostAuthorization authorization)
    {
        var current = _authorization;
        if (current is null
            || current.RoomCode != authorization.RoomCode
            || current.TransmissionInstanceId != authorization.TransmissionInstanceId
            || current.TransmissionRevision != authorization.TransmissionRevision
            || current.Permission != authorization.Permission
            || authorization.ExpiresAt <= current.ExpiresAt)
            return false;
        _authorization = authorization;
        return true;
    }

    internal async Task<ScreenPublisherSnapshot> StartAsync(ScreenCaptureTarget target, string quality, bool audio,
        HostAuthorization authorization, CancellationToken cancellationToken)
    {
        if (authorization.Permission != "screen.publish" || authorization.ExpiresAt <= DateTimeOffset.UtcNow)
            throw new ScreenPublisherException("host_authorization_required");
        if (!_runtime.IsScreenVideoAvailable || _runtime.LaunchPath is null)
            throw new ScreenPublisherException("screen_runtime_unavailable");
        if (audio && !_runtime.IsScreenAudioAvailable)
            throw new ScreenPublisherException("screen_audio_unavailable");
        await _gate.WaitAsync(cancellationToken);
        CancellationTokenSource? attemptCancellation = null;
        try
        {
            await StopLockedAsync();
            attemptCancellation = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            _startCancellation = attemptCancellation;
            var attemptToken = attemptCancellation.Token;
            SetSnapshot(new("preparing", target.Id, target.Type, quality, audio, null, null, "screen_authorized"));
            _authorization = authorization;
            _lease = await _publishClient.StartAsync(authorization, attemptToken);
            SetSnapshot(_snapshot with { DiagnosticCode = "screen_ingress_created" });
            Exception? last = null;
            foreach (var encoder in _runtime.ScreenEncoderCandidates)
            {
                try
                {
                    var diagnostics = new ScreenPipelineDiagnostics();
                    var process = StartProcess(ScreenPipeline.Arguments(target, quality, audio, encoder, _lease.WhipEndpoint), diagnostics);
                    await Task.Delay(900, attemptToken);
                    if (process.HasExited)
                    {
                        diagnostics.Complete(process);
                        process.Dispose();
                        throw new ScreenPublisherException(diagnostics.ErrorCode());
                    }
                    _process = process;
                    _diagnostics = diagnostics;
                    process.EnableRaisingEvents = true;
                    process.Exited += (_, _) => _ = HandleUnexpectedExitAsync(process);
                    SetSnapshot(new("connecting", target.Id, target.Type, quality, audio, encoder, null, "screen_whip_connecting"));
                    await WaitForPublishingAsync(process, diagnostics, authorization, _lease.IngressId, attemptToken);
                    SetSnapshot(new("streaming", target.Id, target.Type, quality, audio, encoder, null, "screen_streaming"));
                    return _snapshot;
                }
                catch (Exception exception) when (exception is not OperationCanceledException)
                {
                    last = exception;
                    if (_process is not null) throw;
                }
            }
            throw new ScreenPublisherException("screen_encoder_unavailable", last);
        }
        catch (OperationCanceledException)
        {
            await StopLockedAsync();
            SetSnapshot(_snapshot with { State = "cancelled", ErrorCode = "screen_publish_cancelled", DiagnosticCode = "screen_publish_cancelled" });
            throw;
        }
        catch (Exception exception)
        {
            await StopLockedAsync();
            var code = exception is ScreenPublisherException screen ? screen.Code : "screen_publish_failed";
            SetSnapshot(_snapshot with { State = "failed", ErrorCode = code, DiagnosticCode = "screen_publish_failed" });
            if (exception is ScreenPublisherException) throw;
            throw new ScreenPublisherException(code, exception);
        }
        finally
        {
            if (ReferenceEquals(_startCancellation, attemptCancellation)) _startCancellation = null;
            attemptCancellation?.Dispose();
            _gate.Release();
        }
    }

    internal async Task<ScreenPublisherSnapshot> StopAsync()
    {
        Interlocked.Exchange(ref _startCancellation, null)?.Cancel();
        await _gate.WaitAsync();
        try
        {
            if (_snapshot.State is not "idle") SetSnapshot(_snapshot with { State = "stopping", ErrorCode = null, DiagnosticCode = "screen_publish_stopped" });
            await StopLockedAsync();
            SetSnapshot(new("idle", null, null, null, false, null, null, "screen_cleanup_completed"));
            return _snapshot;
        }
        finally { _gate.Release(); }
    }

    private Process StartProcess(IReadOnlyList<string> arguments, ScreenPipelineDiagnostics diagnostics)
    {
        var info = new ProcessStartInfo(_runtime.LaunchPath!) { UseShellExecute = false, RedirectStandardError = true,
            RedirectStandardOutput = true, CreateNoWindow = true };
        foreach (var argument in arguments) info.ArgumentList.Add(argument);
        GStreamerRuntime.ConfigureProcessEnvironment(info, _runtime.LaunchPath!);
        var process = new Process { StartInfo = info, EnableRaisingEvents = true };
        process.ErrorDataReceived += (_, eventArgs) => diagnostics.Observe(eventArgs.Data);
        process.OutputDataReceived += (_, eventArgs) => diagnostics.Observe(eventArgs.Data);
        if (!process.Start()) throw new ScreenPublisherException("screen_pipeline_start_failed");
        process.BeginErrorReadLine(); process.BeginOutputReadLine();
        return process;
    }

    private async Task WaitForPublishingAsync(Process process, ScreenPipelineDiagnostics diagnostics,
        HostAuthorization authorization, string ingressId, CancellationToken cancellationToken)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(PublishingTimeout);
        var transientFailures = 0;
        try
        {
            while (true)
            {
                if (process.HasExited)
                {
                    diagnostics.Complete(process);
                    throw new ScreenPublisherException(diagnostics.ErrorCode());
                }
                DesktopPublishStatus status;
                using var requestTimeout = CancellationTokenSource.CreateLinkedTokenSource(timeout.Token);
                requestTimeout.CancelAfter(StatusRequestTimeout);
                try
                {
                    status = await _publishClient.StatusAsync(authorization, ingressId, requestTimeout.Token);
                    transientFailures = 0;
                }
                catch (OperationCanceledException) when (!timeout.IsCancellationRequested)
                {
                    if (++transientFailures >= MaximumTransientStatusFailures)
                        throw new ScreenPublisherException("screen_ingress_status_request_failed");
                    await Task.Delay(StatusPollInterval, timeout.Token);
                    continue;
                }
                catch (MediaEngineException exception) when (CanRetryStatusError(
                    exception.Code, ++transientFailures))
                {
                    await Task.Delay(StatusPollInterval, timeout.Token);
                    continue;
                }
                catch (MediaEngineException exception)
                {
                    throw new ScreenPublisherException(MapStatusError(exception.Code), exception);
                }
                if (status.State == "publishing") return;
                if (status.State == "error") throw new ScreenPublisherException("screen_ingress_error");
                if (status.State == "complete") throw new ScreenPublisherException("screen_whip_failed");
                await Task.Delay(StatusPollInterval, timeout.Token);
            }
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            throw new ScreenPublisherException("screen_publish_confirmation_timeout");
        }
    }

    internal static bool IsTransientStatusError(string code) =>
        code is "ingress_not_found" or "ingress_status_request_failed";

    internal static bool CanRetryStatusError(string code, int failureCount) =>
        IsTransientStatusError(code) && failureCount > 0 && failureCount < MaximumTransientStatusFailures;

    internal static string MapStatusError(string code) => code switch
    {
        "ingress_not_found" => "screen_ingress_not_found",
        "ingress_status_request_failed" => "screen_ingress_status_request_failed",
        "ingress_status_invalid" => "screen_ingress_status_invalid",
        "transmission_changed" => "screen_transmission_changed",
        "host_authorization_expired" => "screen_authorization_expired",
        "host_authorization_required" => "screen_authorization_required",
        "host_authorization_invalid" => "screen_authorization_rejected",
        "ingress_status_forbidden" => "screen_authorization_failed",
        _ => "screen_ingress_status_request_failed",
    };

    private async Task HandleUnexpectedExitAsync(Process process)
    {
        await _gate.WaitAsync();
        try
        {
            if (!ReferenceEquals(_process, process)) return;
            _process = null;
            var diagnostics = Interlocked.Exchange(ref _diagnostics, null);
            diagnostics?.Complete(process);
            process.Dispose();
            var lease = Interlocked.Exchange(ref _lease, null);
            var authorization = Interlocked.Exchange(ref _authorization, null);
            if (lease is not null && authorization is not null)
                await CleanupIngressAsync(authorization, lease.IngressId);
            SetSnapshot(_snapshot with { State = "failed", ErrorCode = diagnostics?.ErrorCode() ?? "screen_pipeline_exited",
                DiagnosticCode = "screen_publish_failed" });
        }
        finally { _gate.Release(); }
    }

    private async Task StopLockedAsync()
    {
        var process = Interlocked.Exchange(ref _process, null);
        _diagnostics = null;
        if (process is not null)
        {
            try { if (!process.HasExited) process.Kill(true); await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(5)); } catch { }
            process.Dispose();
        }
        var lease = Interlocked.Exchange(ref _lease, null);
        var authorization = Interlocked.Exchange(ref _authorization, null);
        if (lease is not null && authorization is not null)
            await CleanupIngressAsync(authorization, lease.IngressId);
    }

    private async Task CleanupIngressAsync(HostAuthorization authorization, string ingressId)
    {
        for (var attempt = 0; attempt < 2; attempt++)
        {
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(2));
            try
            {
                await _publishClient.StopAsync(authorization, ingressId, timeout.Token);
                return;
            }
            catch (Exception) when (attempt == 0)
            {
                await Task.Delay(250);
            }
            catch { return; }
        }
    }

    public async ValueTask DisposeAsync() { await StopAsync(); _gate.Dispose(); }

    private void SetSnapshot(ScreenPublisherSnapshot snapshot)
    {
        _snapshot = snapshot;
        StateChanged?.Invoke(this, snapshot);
    }
}

internal sealed class ScreenPipelineDiagnostics
{
    private readonly object _sync = new();
    private readonly Queue<string> _lines = new();

    internal void Observe(string? line)
    {
        if (string.IsNullOrWhiteSpace(line)) return;
        var safe = line.Length > 512 ? line[..512] : line;
        lock (_sync)
        {
            _lines.Enqueue(safe);
            while (_lines.Count > 40) _lines.Dequeue();
        }
    }

    internal string ErrorCode()
    {
        string text;
        lock (_sync) text = string.Join('\n', _lines);
        if (text.Contains("wasapi", StringComparison.OrdinalIgnoreCase)
            || text.Contains("audio", StringComparison.OrdinalIgnoreCase)) return "screen_audio_failed";
        if (text.Contains("d3d11screen", StringComparison.OrdinalIgnoreCase)
            || text.Contains("capture", StringComparison.OrdinalIgnoreCase)) return "screen_capture_failed";
        if (text.Contains("whip", StringComparison.OrdinalIgnoreCase)
            || text.Contains("webrtc", StringComparison.OrdinalIgnoreCase)) return "screen_whip_failed";
        if (text.Contains("264enc", StringComparison.OrdinalIgnoreCase)
            || text.Contains("encoder", StringComparison.OrdinalIgnoreCase)) return "screen_encoder_failed";
        return "screen_pipeline_exited";
    }

    internal void Complete(Process process)
    {
        try { if (process.HasExited) process.WaitForExit(); } catch { }
    }
}

internal static class ScreenPipeline
{
    internal static IReadOnlyList<string> Arguments(ScreenCaptureTarget target, string quality, bool audio, string encoder, Uri endpoint)
    {
        var (maxWidth, maxHeight, fps, bitrate) = quality switch
        { "economy" => (1280, 720, 15, 1500), "high" => (1920, 1080, 30, 5000), _ => (1280, 720, 30, 2800) };
        var (width, height) = ScaledDimensions(target.Width, target.Height, maxWidth, maxHeight);
        var args = new List<string> { "-e", "whipsink", "name=whip", $"whip-endpoint={endpoint.AbsoluteUri}" };
        args.AddRange(["d3d11screencapturesrc", target.Type == "window" ? "capture-api=wgc" : "capture-api=dxgi",
            target.Type == "window" ? $"window-handle={(ulong)target.Handle}" : $"monitor-handle={(ulong)target.Handle}",
            "show-cursor=true", "!", "queue", "leaky=downstream", "max-size-buffers=2", "!", "d3d11download", "!",
            "videoconvert", "!", "videoscale", "add-borders=true", "!", "videorate", "!",
            $"video/x-raw,width={width},height={height},framerate={fps}/1,pixel-aspect-ratio=1/1", "!" ]);
        args.AddRange(EncoderArguments(encoder, bitrate, fps * 2));
        args.AddRange(["!", "h264parse", "config-interval=-1", "!",
            "video/x-h264,stream-format=byte-stream,alignment=au,profile=constrained-baseline", "!",
            "rtph264pay", "config-interval=-1", "aggregate-mode=zero-latency", "pt=97", "!",
            "application/x-rtp,media=video,encoding-name=H264,payload=97,clock-rate=90000,packetization-mode=(string)1", "!", "whip.sink_0"]);
        if (audio)
        {
            args.AddRange(["wasapi2src", "loopback=true", "low-latency=true", "!", "queue", "leaky=downstream", "max-size-time=2000000000", "!",
                "audioconvert", "!", "audioresample", "!", "audio/x-raw,rate=48000,channels=2", "!",
                "opusenc", "bitrate=128000", "audio-type=generic", "inband-fec=true", "!", "rtpopuspay", "pt=96", "!",
                "application/x-rtp,media=audio,encoding-name=OPUS,payload=96,clock-rate=48000,encoding-params=(string)2", "!", "whip.sink_1"]);
        }
        return args;
    }

    internal static (int Width, int Height) ScaledDimensions(int width, int height, int maxWidth, int maxHeight)
    {
        var scale = Math.Min(1d, Math.Min((double)maxWidth / width, (double)maxHeight / height));
        var scaledWidth = Math.Max(2, (int)Math.Floor(width * scale / 2) * 2);
        var scaledHeight = Math.Max(2, (int)Math.Floor(height * scale / 2) * 2);
        return (scaledWidth, scaledHeight);
    }

    private static IReadOnlyList<string> EncoderArguments(string encoder, int bitrate, int gop) => encoder switch
    {
        "nvh264enc" => ["nvh264enc", $"bitrate={bitrate}", "preset=p3", "tune=low-latency", "rc-mode=cbr", "bframes=0", $"gop-size={gop}", "repeat-sequence-header=true"],
        "mfh264enc" => ["mfh264enc", $"bitrate={bitrate}", "low-latency=true", "rc-mode=cbr", $"gop-size={gop}"],
        _ => ["x264enc", $"bitrate={bitrate}", "speed-preset=veryfast", "tune=zerolatency", "bframes=0", $"key-int-max={gop}", "byte-stream=true"],
    };
}
