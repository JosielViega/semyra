using System.Collections.Concurrent;
using System.Diagnostics;
using System.IO;
using System.IO.Pipes;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace Semyra.Desktop.Media;

internal interface IMpvProcess : IAsyncDisposable
{
    event EventHandler<JsonElement>? MessageReceived;
    event EventHandler? Exited;
    bool IsRunning { get; }
    int? ProcessId { get; }
    long? WorkingSetBytes { get; }
    string PipeName { get; }
    IReadOnlyList<string> Arguments { get; }
    Task StartAsync(CancellationToken cancellationToken);
    Task<JsonElement> CommandAsync(string command, IReadOnlyList<object?> arguments, CancellationToken cancellationToken);
}

internal sealed class MpvProcess : IMpvProcess
{
    private readonly MpvRuntime _runtime;
    private readonly nint _hostHandle;
    private readonly ConcurrentDictionary<long, TaskCompletionSource<JsonElement>> _pending = new();
    private readonly SemaphoreSlim _writeGate = new(1, 1);
    private Process? _process;
    private NamedPipeClientStream? _pipe;
    private StreamReader? _reader;
    private StreamWriter? _writer;
    private Task? _readerTask;
    private long _nextRequestId;
    private bool _disposed;

    internal MpvProcess(MpvRuntime runtime, nint hostHandle, string? pipeName = null)
    {
        _runtime = runtime;
        _hostHandle = hostHandle;
        PipeName = pipeName ?? CreatePipeName();
        Arguments = BuildArguments(hostHandle, PipeName);
    }

    public event EventHandler<JsonElement>? MessageReceived;
    public event EventHandler? Exited;
    public bool IsRunning => _process is { HasExited: false };
    public int? ProcessId => IsRunning ? _process!.Id : null;
    public long? WorkingSetBytes
    {
        get
        {
            try { return IsRunning ? _process!.WorkingSet64 : null; }
            catch { return null; }
        }
    }
    public string PipeName { get; }
    public IReadOnlyList<string> Arguments { get; }

    internal static string CreatePipeName() => "semyra-mpv-" + Convert.ToHexString(RandomNumberGenerator.GetBytes(24)).ToLowerInvariant();

    internal static IReadOnlyList<string> BuildArguments(nint hostHandle, string pipeName) =>
    [
        "--no-config",
        "--idle=yes",
        "--force-window=yes",
        "--osc=no",
        "--input-default-bindings=no",
        "--terminal=no",
        "--msg-level=all=error",
        "--hwdec=auto",
        "--vo=gpu-next",
        "--gpu-api=d3d11",
        "--keep-open=yes",
        "--cache=yes",
        "--demuxer-max-bytes=50MiB",
        "--network-timeout=10",
        $"--wid={hostHandle.ToInt64()}",
        $"--input-ipc-server=\\\\.\\pipe\\{pipeName}",
    ];

    internal ProcessStartInfo BuildStartInfo()
    {
        if (!_runtime.Available || string.IsNullOrWhiteSpace(_runtime.ExecutablePath))
        {
            throw new MediaEngineException("mpv_runtime_unavailable");
        }
        var info = new ProcessStartInfo
        {
            FileName = _runtime.ExecutablePath,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };
        foreach (var argument in Arguments)
        {
            info.ArgumentList.Add(argument);
        }
        return info;
    }

    public async Task StartAsync(CancellationToken cancellationToken)
    {
        if (IsRunning)
        {
            return;
        }
        _process = new Process { StartInfo = BuildStartInfo(), EnableRaisingEvents = true };
        _process.Exited += OnExited;
        if (!_process.Start())
        {
            throw new MediaEngineException("mpv_process_start_failed");
        }

        _ = DrainAsync(_process.StandardOutput);
        _ = DrainAsync(_process.StandardError);
        _pipe = new NamedPipeClientStream(".", PipeName, PipeDirection.InOut, PipeOptions.Asynchronous);
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(TimeSpan.FromSeconds(8));
        try
        {
            await _pipe.ConnectAsync(timeout.Token);
        }
        catch (Exception exception) when (exception is IOException or OperationCanceledException or TimeoutException)
        {
            await DisposeAsync();
            throw new MediaEngineException("mpv_ipc_unavailable", exception);
        }
        _reader = new StreamReader(_pipe, new UTF8Encoding(false), false, 4096, leaveOpen: true);
        _writer = new StreamWriter(_pipe, new UTF8Encoding(false), 4096, leaveOpen: true) { AutoFlush = true };
        _readerTask = ReadLoopAsync();
    }

    public async Task<JsonElement> CommandAsync(string command, IReadOnlyList<object?> arguments, CancellationToken cancellationToken)
    {
        if (_writer is null || !IsRunning)
        {
            throw new MediaEngineException("mpv_ipc_unavailable");
        }
        var requestId = Interlocked.Increment(ref _nextRequestId);
        var completion = new TaskCompletionSource<JsonElement>(TaskCreationOptions.RunContinuationsAsynchronously);
        if (!_pending.TryAdd(requestId, completion))
        {
            throw new MediaEngineException("mpv_ipc_unavailable");
        }
        var payload = SerializeCommand(command, arguments, requestId);
        await _writeGate.WaitAsync(cancellationToken);
        try
        {
            await _writer.WriteLineAsync(payload.AsMemory(), cancellationToken);
        }
        finally
        {
            _writeGate.Release();
        }
        try
        {
            JsonElement response;
            try
            {
                response = await completion.Task.WaitAsync(TimeSpan.FromSeconds(5), cancellationToken);
            }
            catch (TimeoutException exception)
            {
                throw new MediaEngineException("mpv_timeout", exception);
            }
            if (response.TryGetProperty("error", out var error)
                && error.ValueKind == JsonValueKind.String
                && !string.Equals(error.GetString(), "success", StringComparison.OrdinalIgnoreCase))
            {
                throw new MediaEngineException("mpv_ipc_command_failed");
            }
            return response;
        }
        finally
        {
            _pending.TryRemove(requestId, out _);
        }
    }

    internal static string SerializeCommand(string command, IReadOnlyList<object?> arguments, long requestId)
    {
        var values = new List<object?> { command };
        values.AddRange(arguments);
        return JsonSerializer.Serialize(new { command = values, request_id = requestId });
    }

    private async Task ReadLoopAsync()
    {
        try
        {
            while (_reader is not null && await _reader.ReadLineAsync() is { } line)
            {
                try
                {
                    using var document = JsonDocument.Parse(line);
                    var message = document.RootElement.Clone();
                    if (message.TryGetProperty("request_id", out var idElement)
                        && idElement.TryGetInt64(out var id)
                        && _pending.TryRemove(id, out var completion))
                    {
                        completion.TrySetResult(message);
                    }
                    else
                    {
                        MessageReceived?.Invoke(this, message);
                    }
                }
                catch (JsonException)
                {
                    // MPV output is untrusted input; malformed lines are ignored and never logged.
                }
            }
        }
        catch (Exception) when (_disposed || !IsRunning)
        {
        }
    }

    private static async Task DrainAsync(StreamReader reader)
    {
        try
        {
            while (await reader.ReadLineAsync() is not null) { }
        }
        catch { }
    }

    private void OnExited(object? sender, EventArgs e)
    {
        foreach (var completion in _pending.Values)
        {
            completion.TrySetException(new MediaEngineException("mpv_process_exited"));
        }
        Exited?.Invoke(this, EventArgs.Empty);
    }

    public async ValueTask DisposeAsync()
    {
        if (_disposed) return;
        _disposed = true;
        if (_process is { HasExited: false })
        {
            try
            {
                if (_writer is not null)
                {
                    await _writer.WriteLineAsync("{\"command\":[\"quit\"]}");
                }
                using var timeout = new CancellationTokenSource(TimeSpan.FromMilliseconds(400));
                await _process.WaitForExitAsync(timeout.Token);
            }
            catch
            {
                try { _process.Kill(true); } catch { }
            }
        }
        _pipe?.Dispose();
        if (_readerTask is not null)
        {
            try { await _readerTask.WaitAsync(TimeSpan.FromMilliseconds(400)); } catch { }
        }
        _process?.Dispose();
        _writeGate.Dispose();
    }
}
