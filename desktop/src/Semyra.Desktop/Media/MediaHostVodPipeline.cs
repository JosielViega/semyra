using System.Diagnostics;
using System.IO;
using System.Text.Json;
using Semyra.MediaHost;

namespace Semyra.Desktop.Media;

internal sealed class MediaHostVodPipeline : IVodMediaPipeline
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
    };
    private readonly GStreamerRuntime _runtime;
    private readonly Uri _source;
    private readonly Uri _whip;
    private readonly string _sessionId = Convert.ToHexString(System.Security.Cryptography.RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
    private readonly SemaphoreSlim _commands = new(1, 1);
    private Process? _process;

    internal MediaHostVodPipeline(GStreamerRuntime runtime, Uri source, Uri whip)
    {
        _runtime = runtime;
        _source = source;
        _whip = whip;
    }

    public async Task StartAsync(CancellationToken cancellationToken)
    {
        var executable = Path.Combine(AppContext.BaseDirectory, "Semyra.MediaHost.exe");
        if (!File.Exists(executable) || _runtime.LaunchPath is null) throw new MediaEngineException("vod_publish_unavailable");
        var startInfo = new ProcessStartInfo(executable)
        {
            UseShellExecute = false,
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
            WorkingDirectory = AppContext.BaseDirectory,
        };
        var gstBin = Path.GetDirectoryName(_runtime.LaunchPath)!;
        startInfo.Environment["PATH"] = gstBin + Path.PathSeparator + (startInfo.Environment.TryGetValue("PATH", out var path) ? path : string.Empty);
        _process = Process.Start(startInfo) ?? throw new MediaEngineException("pipeline_start_failed");
        _ = _process.StandardError.ReadToEndAsync(cancellationToken);
        var response = await SendAsync(new MediaHostCommand("start", _sessionId, _source.AbsoluteUri, _whip.AbsoluteUri), cancellationToken);
        Ensure(response);
    }

    public Task<VodPublisherSnapshot> PauseAsync(CancellationToken cancellationToken) => ControlAsync("pause", null, cancellationToken);
    public Task<VodPublisherSnapshot> PlayAsync(CancellationToken cancellationToken) => ControlAsync("play", null, cancellationToken);
    public Task<VodPublisherSnapshot> SeekAsync(long positionMs, CancellationToken cancellationToken) => ControlAsync("seek", positionMs, cancellationToken);
    public Task<VodPublisherSnapshot> SnapshotAsync(CancellationToken cancellationToken) => ControlAsync("snapshot", null, cancellationToken);

    public async Task WaitForExitAsync(CancellationToken cancellationToken)
    {
        var process = _process ?? throw new MediaEngineException("pipeline_start_failed");
        while (!cancellationToken.IsCancellationRequested)
        {
            if (process.HasExited) throw new MediaEngineException("pipeline_exited");
            await Task.Delay(TimeSpan.FromSeconds(1), cancellationToken);
            var snapshot = await SnapshotAsync(cancellationToken);
            if (snapshot.State == "ended") return;
            if (snapshot.State == "failed") throw new MediaEngineException("pipeline_error");
        }
    }

    public async Task StopAsync()
    {
        var process = _process;
        if (process is null) return;
        try
        {
            if (!process.HasExited) _ = await SendAsync(new MediaHostCommand("stop", _sessionId), CancellationToken.None);
            process.StandardInput.Close();
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5));
            if (!process.HasExited) await process.WaitForExitAsync(timeout.Token);
        }
        catch { if (!process.HasExited) process.Kill(true); }
    }

    private async Task<VodPublisherSnapshot> ControlAsync(string command, long? positionMs, CancellationToken cancellationToken)
    {
        var response = await SendAsync(new MediaHostCommand(command, _sessionId, PositionMs: positionMs), cancellationToken);
        Ensure(response);
        return new VodPublisherSnapshot(response.State, response.PositionMs, response.DurationMs, response.Seekable, response.SessionId!);
    }

    private async Task<MediaHostSnapshot> SendAsync(MediaHostCommand command, CancellationToken cancellationToken)
    {
        var process = _process ?? throw new MediaEngineException("pipeline_start_failed");
        await _commands.WaitAsync(cancellationToken);
        try
        {
            if (process.HasExited) throw new MediaEngineException("pipeline_exited");
            await process.StandardInput.WriteLineAsync(JsonSerializer.Serialize(command, JsonOptions).AsMemory(), cancellationToken);
            await process.StandardInput.FlushAsync(cancellationToken);
            var line = await process.StandardOutput.ReadLineAsync(cancellationToken);
            if (line is null) throw new MediaEngineException("pipeline_exited");
            return JsonSerializer.Deserialize<MediaHostSnapshot>(line, JsonOptions) ?? throw new MediaEngineException("media_host_protocol_error");
        }
        catch (JsonException exception) { throw new MediaEngineException("media_host_protocol_error", exception); }
        finally { _commands.Release(); }
    }

    private static void Ensure(MediaHostSnapshot response)
    {
        if (!response.Ok) throw new MediaEngineException(response.ErrorCode ?? "media_host_failed");
    }

    public async ValueTask DisposeAsync()
    {
        await StopAsync();
        _process?.Dispose();
        _process = null;
        _commands.Dispose();
    }
}
