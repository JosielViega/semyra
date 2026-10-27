using System.Diagnostics;

namespace Semyra.Desktop.Media;

internal sealed class GStreamerPullProcess : IVodMediaPipeline
{
    private readonly string _executable;
    private readonly IReadOnlyList<string> _arguments;
    private Process? _process;

    internal GStreamerPullProcess(string executable, IReadOnlyList<string> arguments)
    {
        _executable = executable;
        _arguments = arguments;
    }

    public async Task StartAsync(CancellationToken cancellationToken)
    {
        var start = new ProcessStartInfo(_executable)
        {
            UseShellExecute = false, RedirectStandardOutput = true, RedirectStandardError = true, CreateNoWindow = true,
        };
        foreach (var argument in _arguments) start.ArgumentList.Add(argument);
        GStreamerRuntime.ConfigureProcessEnvironment(start, _executable);
        _process = Process.Start(start) ?? throw new MediaEngineException("pipeline_start_failed");
        _process.BeginOutputReadLine();
        _process.BeginErrorReadLine();
        await Task.Delay(350, cancellationToken);
        if (_process.HasExited) throw new MediaEngineException("pipeline_exited");
    }

    public async Task WaitForExitAsync(CancellationToken cancellationToken)
    {
        if (_process is null) throw new MediaEngineException("pipeline_start_failed");
        await _process.WaitForExitAsync(cancellationToken);
        if (_process.ExitCode != 0) throw new MediaEngineException("pipeline_exited");
    }

    public Task<VodPublisherSnapshot> PauseAsync(CancellationToken cancellationToken) => Unsupported();
    public Task<VodPublisherSnapshot> PlayAsync(CancellationToken cancellationToken) => Unsupported();
    public Task<VodPublisherSnapshot> SeekAsync(long positionMs, CancellationToken cancellationToken) => Unsupported();
    public Task<VodPublisherSnapshot> SnapshotAsync(CancellationToken cancellationToken) => Unsupported();

    private static Task<VodPublisherSnapshot> Unsupported() =>
        Task.FromException<VodPublisherSnapshot>(new MediaEngineException("vod_control_unavailable"));

    public async Task StopAsync()
    {
        if (_process is null) return;
        try
        {
            if (!_process.HasExited) _process.Kill(true);
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5));
            await _process.WaitForExitAsync(timeout.Token);
        }
        catch { try { if (!_process.HasExited) _process.Kill(true); } catch { } }
    }

    public async ValueTask DisposeAsync()
    {
        await StopAsync();
        _process?.Dispose();
        _process = null;
    }
}
