using Semyra.Desktop.Iptv;

namespace Semyra.Desktop.Media;

public sealed class MediaEngine : IDisposable
{
    private static readonly TimeSpan[] ReconnectDelays =
    [
        TimeSpan.FromSeconds(1),
        TimeSpan.FromSeconds(2),
        TimeSpan.FromSeconds(5),
    ];

    private readonly Func<long, IptvPlaybackChannel> _resolveChannel;
    private readonly IProviderStreamClient _provider;
    private readonly IMediaPipelineFactory _pipelines;
    private readonly Func<TimeSpan, CancellationToken, Task> _delay;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly object _snapshotLock = new();
    private CancellationTokenSource? _sessionCancellation;
    private Task? _sessionTask;
    private long? _activeChannelId;
    private MediaSnapshot _snapshot = MediaSnapshot.Idle;
    private bool _disposed;

    internal MediaEngine(
        Func<long, IptvPlaybackChannel> resolveChannel,
        GStreamerRuntime runtime,
        IProviderStreamClient provider,
        IMediaPipelineFactory pipelines,
        Func<TimeSpan, CancellationToken, Task>? delay = null)
    {
        _resolveChannel = resolveChannel;
        Runtime = runtime;
        _provider = provider;
        _pipelines = pipelines;
        _delay = delay ?? Task.Delay;
    }

    public GStreamerRuntime Runtime { get; }
    public bool IsAvailable => Runtime.IsAvailable;
    public event EventHandler<MediaSnapshot>? StateChanged;

    internal static MediaEngine CreateDefault(IptvCatalogService catalog, GStreamerRuntime runtime)
    {
        return new MediaEngine(
            catalog.ResolveChannelForPlayback,
            runtime,
            new ProviderStreamClient(),
            new MediaPipelineFactory(runtime));
    }

    public MediaSnapshot Snapshot()
    {
        lock (_snapshotLock)
        {
            return _snapshot;
        }
    }

    public async Task<MediaSnapshot> StartAsync(long channelId, CancellationToken cancellationToken = default)
    {
        TaskCompletionSource<MediaSnapshot>? ready = null;
        await _gate.WaitAsync(cancellationToken);
        try
        {
            if (!IsAvailable)
            {
                var failed = Update(MediaState.Failed, null, null, 0, "media_runtime_unavailable");
                throw new MediaEngineException(failed.LastErrorCode!);
            }
            if (_activeChannelId == channelId && _sessionTask is { IsCompleted: false })
            {
                return Snapshot();
            }

            await StopSessionLockedAsync();
            IptvPlaybackChannel channel;
            try
            {
                channel = _resolveChannel(channelId);
            }
            catch (IptvPlaybackException exception)
            {
                Update(MediaState.Failed, channelId, null, 0, exception.Code);
                throw new MediaEngineException(exception.Code, exception);
            }

            _activeChannelId = channelId;
            _sessionCancellation = new CancellationTokenSource();
            ready = new TaskCompletionSource<MediaSnapshot>(TaskCreationOptions.RunContinuationsAsynchronously);
            Update(MediaState.Preparing, channel.Id, channel.Name, 1, null);
            _sessionTask = RunSessionAsync(channel, _sessionCancellation.Token, ready);
        }
        finally
        {
            _gate.Release();
        }

        return await ready.Task.WaitAsync(cancellationToken);
    }

    public async Task<MediaSnapshot> StopAsync()
    {
        await _gate.WaitAsync();
        try
        {
            await StopSessionLockedAsync();
            return Update(MediaState.Idle, null, null, 0, null);
        }
        finally
        {
            _gate.Release();
        }
    }

    private async Task RunSessionAsync(
        IptvPlaybackChannel channel,
        CancellationToken cancellationToken,
        TaskCompletionSource<MediaSnapshot> ready)
    {
        for (var attempt = 1; attempt <= ReconnectDelays.Length + 1; attempt++)
        {
            try
            {
                if (attempt > 1)
                {
                    Update(MediaState.Reconnecting, channel.Id, channel.Name, attempt, null);
                }
                await using var providerStream = await _provider.OpenAsync(channel.StreamUri, cancellationToken);
                var initialBuffer = await MpegTsValidator.ReadAndValidateAsync(providerStream, cancellationToken);
                await using var pipeline = _pipelines.Create();
                await pipeline.StartAsync(initialBuffer, cancellationToken);
                var streaming = Update(MediaState.Streaming, channel.Id, channel.Name, attempt, null);
                ready.TrySetResult(streaming);
                await pipeline.PumpAsync(providerStream, cancellationToken);
                throw new MediaEngineException("pipeline_exited");
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                ready.TrySetResult(Snapshot());
                return;
            }
            catch (Exception exception)
            {
                var code = exception is MediaEngineException mediaException
                    ? mediaException.Code
                    : "provider_connect_failed";
                if (attempt > ReconnectDelays.Length)
                {
                    var failed = Update(MediaState.Failed, channel.Id, channel.Name, attempt, code);
                    ready.TrySetResult(failed);
                    return;
                }
                Update(MediaState.Reconnecting, channel.Id, channel.Name, attempt, code);
                try
                {
                    await _delay(ReconnectDelays[attempt - 1], cancellationToken);
                }
                catch (OperationCanceledException)
                {
                    ready.TrySetResult(Snapshot());
                    return;
                }
            }
        }
    }

    private async Task StopSessionLockedAsync()
    {
        var cancellation = _sessionCancellation;
        var task = _sessionTask;
        _sessionCancellation = null;
        _sessionTask = null;
        _activeChannelId = null;
        if (cancellation is not null)
        {
            cancellation.Cancel();
            if (task is not null)
            {
                try
                {
                    await task;
                }
                catch (OperationCanceledException)
                {
                }
            }
            cancellation.Dispose();
        }
    }

    private MediaSnapshot Update(MediaState state, long? channelId, string? channelName, int attempt, string? errorCode)
    {
        var snapshot = new MediaSnapshot(state.ToString().ToLowerInvariant(), channelId, channelName, attempt, errorCode);
        lock (_snapshotLock)
        {
            _snapshot = snapshot;
        }
        StateChanged?.Invoke(this, snapshot);
        return snapshot;
    }

    public void Dispose()
    {
        if (_disposed)
        {
            return;
        }
        _disposed = true;
        StopAsync().GetAwaiter().GetResult();
        _provider.Dispose();
        _gate.Dispose();
    }
}
