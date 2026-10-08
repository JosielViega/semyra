using System.Buffers;
using System.Diagnostics;
using System.IO;

namespace Semyra.Desktop.Media;

internal sealed class GStreamerProcess : IMediaPipeline
{
    private readonly string _executable;
    private readonly IReadOnlyList<string> _arguments;
    private readonly string? _workingDirectory;
    private Process? _process;

    public GStreamerProcess(string executable, IReadOnlyList<string> arguments, string? workingDirectory = null)
    {
        _executable = executable;
        _arguments = arguments;
        _workingDirectory = workingDirectory;
    }

    internal ProcessStartInfo CreateStartInfo()
    {
        var startInfo = new ProcessStartInfo(_executable)
        {
            UseShellExecute = false,
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
            WorkingDirectory = _workingDirectory ?? AppContext.BaseDirectory,
        };
        foreach (var argument in _arguments)
        {
            startInfo.ArgumentList.Add(argument);
        }
        GStreamerRuntime.ConfigureProcessEnvironment(startInfo, _executable);
        return startInfo;
    }

    public async Task StartAsync(ReadOnlyMemory<byte> initialBuffer, CancellationToken cancellationToken)
    {
        try
        {
            _process = Process.Start(CreateStartInfo()) ?? throw new MediaEngineException("pipeline_start_failed");
            _process.BeginOutputReadLine();
            _process.BeginErrorReadLine();
            await _process.StandardInput.BaseStream.WriteAsync(initialBuffer, cancellationToken);
            await _process.StandardInput.BaseStream.FlushAsync(cancellationToken);
            await Task.Delay(150, cancellationToken);
            if (_process.HasExited)
            {
                throw new MediaEngineException("pipeline_exited");
            }
        }
        catch (OperationCanceledException)
        {
            throw;
        }
        catch (MediaEngineException)
        {
            throw;
        }
        catch (Exception exception)
        {
            throw new MediaEngineException("pipeline_start_failed", exception);
        }
    }

    public async Task PumpAsync(Stream providerStream, CancellationToken cancellationToken)
    {
        if (_process is null)
        {
            throw new MediaEngineException("pipeline_start_failed");
        }
        var buffer = ArrayPool<byte>.Shared.Rent(64 * 1024);
        try
        {
            while (true)
            {
                cancellationToken.ThrowIfCancellationRequested();
                if (_process.HasExited)
                {
                    throw new MediaEngineException("pipeline_exited");
                }
                var length = await providerStream.ReadAsync(buffer.AsMemory(0, buffer.Length), cancellationToken);
                if (length == 0)
                {
                    throw new MediaEngineException("pipeline_exited");
                }
                await _process.StandardInput.BaseStream.WriteAsync(buffer.AsMemory(0, length), cancellationToken);
                await _process.StandardInput.BaseStream.FlushAsync(cancellationToken);
            }
        }
        catch (OperationCanceledException)
        {
            throw;
        }
        catch (MediaEngineException)
        {
            throw;
        }
        catch (Exception exception)
        {
            throw new MediaEngineException("pipeline_write_failed", exception);
        }
        finally
        {
            ArrayPool<byte>.Shared.Return(buffer);
        }
    }

    public async Task StopAsync()
    {
        var process = _process;
        if (process is null)
        {
            return;
        }
        try
        {
            process.StandardInput.Close();
            if (!process.HasExited)
            {
                process.Kill(true);
            }
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5));
            await process.WaitForExitAsync(timeout.Token);
        }
        catch
        {
            if (!process.HasExited)
            {
                process.Kill(true);
            }
        }
    }

    public async ValueTask DisposeAsync()
    {
        await StopAsync();
        _process?.Dispose();
        _process = null;
    }
}
