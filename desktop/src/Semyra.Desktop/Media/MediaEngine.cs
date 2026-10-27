using Semyra.Desktop.Iptv;
using Semyra.Desktop.Host;
using Semyra.Desktop.Desktop;

namespace Semyra.Desktop.Media;

public sealed class MediaEngine : IDisposable, IAsyncDisposable
{
    private static readonly TimeSpan[] ReconnectDelays =
    [
        TimeSpan.FromSeconds(1),
        TimeSpan.FromSeconds(2),
        TimeSpan.FromSeconds(5),
    ];

    private readonly Func<long, IptvPlaybackChannel> _resolveChannel;
    private readonly Func<string, long, IptvVodPlaybackTarget>? _resolveVod;
    private readonly IProviderStreamClient _provider;
    private readonly IMediaPipelineFactory _pipelines;
    private readonly Func<TimeSpan, CancellationToken, Task> _delay;
    private readonly IDesktopPublishClient? _publishClient;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly object _snapshotLock = new();
    private CancellationTokenSource? _sessionCancellation;
    private Task? _sessionTask;
    private long? _activeChannelId;
    private string _activeMode = "local";
    private string _activeContentKind = "live";
    private IVodMediaPipeline? _activeVodPipeline;
    private HostAuthorization? _activeVodAuthorization;
    private MediaSnapshot _snapshot = MediaSnapshot.Idle;
    private bool _disposed;

    internal MediaEngine(
        Func<long, IptvPlaybackChannel> resolveChannel,
        GStreamerRuntime runtime,
        IProviderStreamClient provider,
        IMediaPipelineFactory pipelines,
        Func<TimeSpan, CancellationToken, Task>? delay = null,
        IDesktopPublishClient? publishClient = null,
        Func<string, long, IptvVodPlaybackTarget>? resolveVod = null)
    {
        _resolveChannel = resolveChannel;
        _resolveVod = resolveVod;
        Runtime = runtime;
        _provider = provider;
        _pipelines = pipelines;
        _delay = delay ?? Task.Delay;
        _publishClient = publishClient;
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
            new MediaPipelineFactory(runtime),
            publishClient: new DesktopPublishClient(() => SemyraWebConfiguration.Load().BaseUri),
            resolveVod: catalog.ResolveVodForPlayback);
    }

    public MediaSnapshot Snapshot()
    {
        lock (_snapshotLock)
        {
            return _snapshot;
        }
    }

    public async Task<MediaSnapshot> StartPublishAsync(long channelId, Func<HostAuthorization?> authorizationProvider, CancellationToken cancellationToken = default)
    {
        if (!Runtime.IsWhipAvailable || _publishClient is null)
        {
            throw new MediaEngineException("whip_runtime_unavailable");
        }
        return await StartCoreAsync(channelId, "publish", authorizationProvider, cancellationToken);
    }

    public async Task<MediaSnapshot> StartVodPublishAsync(string kind, long mediaId, Func<HostAuthorization?> authorizationProvider, CancellationToken cancellationToken = default)
    {
        if (!Runtime.IsVodPublishAvailable || _publishClient is null || _resolveVod is null)
            throw new MediaEngineException("vod_publish_unavailable");
        if (kind is not ("movie" or "episode") || mediaId <= 0) throw new MediaEngineException("invalid_media_kind");

        TaskCompletionSource<MediaSnapshot> ready;
        await _gate.WaitAsync(cancellationToken);
        try
        {
            if (_activeChannelId == mediaId && _activeMode == "publish" && _activeContentKind == kind && _sessionTask is { IsCompleted: false })
                return Snapshot();
            await StopSessionLockedAsync();
            IptvVodPlaybackTarget target;
            try { target = _resolveVod(kind, mediaId); }
            catch (IptvValidationException exception) { throw new MediaEngineException("vod_not_found", exception); }
            _activeChannelId = mediaId;
            _activeMode = "publish";
            _activeContentKind = kind;
            _sessionCancellation = new CancellationTokenSource();
            ready = new TaskCompletionSource<MediaSnapshot>(TaskCreationOptions.RunContinuationsAsynchronously);
            Update(MediaState.Preparing, mediaId, target.Name, 1, null, "vod", kind);
            _sessionTask = RunVodSessionAsync(target, authorizationProvider, _sessionCancellation.Token, ready);
        }
        finally { _gate.Release(); }
        return await ready.Task.WaitAsync(cancellationToken);
    }

    private async Task RunVodSessionAsync(IptvVodPlaybackTarget target, Func<HostAuthorization?> authorizationProvider,
        CancellationToken cancellationToken, TaskCompletionSource<MediaSnapshot> ready)
    {
        DesktopPublishLease? lease = null;
        HostAuthorization? attemptAuthorization = null;
        try
        {
            attemptAuthorization = ValidAuthorization(authorizationProvider);
            lease = await _publishClient!.StartAsync(attemptAuthorization, cancellationToken);
            await using var source = await VodPublishSource.StartAsync(target.StreamUri, cancellationToken);
            _ = await VodMediaProbe.InspectAsync(Runtime, source.LocalUri, cancellationToken);
            await using var pipeline = _pipelines.CreateVodPublish(source.LocalUri, lease.WhipEndpoint);
            await pipeline.StartAsync(cancellationToken);
            var native = await pipeline.SnapshotAsync(cancellationToken);
            lock (_snapshotLock)
            {
                _activeVodPipeline = pipeline;
                _activeVodAuthorization = attemptAuthorization;
            }
            var streaming = Update(MediaState.Streaming, target.Id, target.Name, 1, null, "vod", target.Kind,
                native.SessionId, native.PositionMs / 1000d, native.DurationMs / 1000d, native.Seekable, native.State == "paused");
            ready.TrySetResult(streaming);
            var completion = pipeline.WaitForExitAsync(cancellationToken);
            while (!completion.IsCompleted)
            {
                await _delay(TimeSpan.FromSeconds(1), cancellationToken);
                var current = ValidAuthorization(authorizationProvider);
                if (!SameContext(current, attemptAuthorization)) throw new MediaEngineException("transmission_changed");
            }
            await completion;
            Update(MediaState.Ended, target.Id, target.Name, 1, null, "vod", target.Kind);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { ready.TrySetResult(Snapshot()); }
        catch (Exception exception)
        {
            var code = exception is MediaEngineException media ? media.Code : "provider_connect_failed";
            var failed = Update(MediaState.Failed, target.Id, target.Name, 1, code, "vod", target.Kind);
            ready.TrySetResult(failed);
        }
        finally
        {
            lock (_snapshotLock)
            {
                _activeVodPipeline = null;
                _activeVodAuthorization = null;
            }
            if (lease is not null && attemptAuthorization is not null)
            {
                try
                {
                    var latest = authorizationProvider();
                    await _publishClient!.StopAsync(latest is not null && SameContext(latest, attemptAuthorization) ? latest : attemptAuthorization, lease.IngressId, CancellationToken.None);
                }
                catch { }
            }
        }
    }

    public async Task<MediaSnapshot> ControlVodPublishAsync(
        string action,
        long? positionMs,
        HostAuthorization authorization,
        CancellationToken cancellationToken = default)
    {
        IVodMediaPipeline pipeline;
        HostAuthorization active;
        lock (_snapshotLock)
        {
            pipeline = _activeVodPipeline ?? throw new MediaEngineException("vod_control_unavailable");
            active = _activeVodAuthorization ?? throw new MediaEngineException("host_authorization_required");
        }
        if (_activeMode != "publish" || _activeContentKind is not ("movie" or "episode") || !SameContext(authorization, active))
            throw new MediaEngineException("transmission_changed");
        var native = action switch
        {
            "pause" => await pipeline.PauseAsync(cancellationToken),
            "play" => await pipeline.PlayAsync(cancellationToken),
            "seek" when positionMs is >= 0 => await pipeline.SeekAsync(positionMs.Value, cancellationToken),
            "snapshot" => await pipeline.SnapshotAsync(cancellationToken),
            _ => throw new MediaEngineException("invalid_media_control"),
        };
        var state = native.State switch
        {
            "paused" => MediaState.Streaming,
            "playing" => MediaState.Streaming,
            "ended" => MediaState.Ended,
            _ => MediaState.Failed,
        };
        return Update(state, Snapshot().ChannelId, Snapshot().ChannelName, 1,
            state == MediaState.Failed ? "media_host_failed" : null, "vod", _activeContentKind,
            native.SessionId, native.PositionMs / 1000d, native.DurationMs / 1000d, native.Seekable, native.State == "paused");
    }

    private async Task<MediaSnapshot> StartCoreAsync(
        long channelId,
        string mode,
        Func<HostAuthorization?>? authorizationProvider,
        CancellationToken cancellationToken)
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
            if (_activeChannelId == channelId && _activeMode == mode && _sessionTask is { IsCompleted: false })
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
            _activeMode = mode;
            _activeContentKind = "live";
            _sessionCancellation = new CancellationTokenSource();
            ready = new TaskCompletionSource<MediaSnapshot>(TaskCreationOptions.RunContinuationsAsynchronously);
            Update(MediaState.Preparing, channel.Id, channel.Name, 1, null);
            _sessionTask = RunSessionAsync(channel, mode, authorizationProvider, _sessionCancellation.Token, ready);
        }
        finally
        {
            _gate.Release();
        }

        var snapshot = await ready.Task.WaitAsync(cancellationToken);
        return snapshot;
    }

    public async Task<MediaSnapshot> StopAsync()
    {
        InvalidatePlaybackAndCancel();
        await _gate.WaitAsync();
        try
        {
            await StopSessionLockedAsync();
            var snapshot = Update(MediaState.Idle, null, null, 0, null);
            return snapshot;
        }
        finally
        {
            _gate.Release();
        }
    }

    private async Task RunSessionAsync(
        IptvPlaybackChannel channel,
        string mode,
        Func<HostAuthorization?>? authorizationProvider,
        CancellationToken cancellationToken,
        TaskCompletionSource<MediaSnapshot> ready)
    {
        for (var attempt = 1; attempt <= ReconnectDelays.Length + 1; attempt++)
        {
            try
            {
                DesktopPublishLease? publishLease = null;
                HostAuthorization? attemptAuthorization = null;
                using var attemptCancellation = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                var attemptToken = attemptCancellation.Token;
                if (attempt > 1)
                {
                    Update(MediaState.Reconnecting, channel.Id, channel.Name, attempt, null);
                }
                try
                {
                    if (mode == "publish")
                    {
                        attemptAuthorization = ValidAuthorization(authorizationProvider);
                        publishLease = await _publishClient!.StartAsync(attemptAuthorization, attemptToken);
                    }
                    await using var providerStream = await _provider.OpenAsync(channel.StreamUri, attemptToken);
                    var initialBuffer = await MpegTsValidator.ReadAndValidateAsync(providerStream, attemptToken);
                    await using var pipeline = _pipelines.CreatePublish(publishLease!.WhipEndpoint);
                    await pipeline.StartAsync(initialBuffer, attemptToken);
                    var streaming = Update(MediaState.Streaming, channel.Id, channel.Name, attempt, null);
                    ready.TrySetResult(streaming);
                    var pump = pipeline.PumpAsync(providerStream, attemptToken);
                    if (mode == "publish")
                    {
                        while (!pump.IsCompleted)
                        {
                            await _delay(TimeSpan.FromSeconds(1), attemptToken);
                            var current = ValidAuthorization(authorizationProvider);
                            if (!SameContext(current, attemptAuthorization!))
                            {
                                throw new MediaEngineException("transmission_changed");
                            }
                        }
                    }
                    await pump;
                    throw new MediaEngineException("pipeline_exited");
                }
                finally
                {
                    attemptCancellation.Cancel();
                    if (publishLease is not null && attemptAuthorization is not null)
                    {
                        try
                        {
                            var latest = authorizationProvider?.Invoke();
                            await _publishClient!.StopAsync(latest is not null && SameContext(latest, attemptAuthorization) ? latest : attemptAuthorization, publishLease.IngressId, CancellationToken.None);
                        }
                        catch { }
                    }
                }
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
                if (code is "host_authorization_required" or "host_authorization_expired" or "transmission_changed"
                    || attempt > ReconnectDelays.Length)
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
        _activeMode = "local";
        _activeContentKind = "live";
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

    internal void InvalidatePlaybackAndCancel()
    {
        try
        {
            Volatile.Read(ref _sessionCancellation)?.Cancel();
        }
        catch (ObjectDisposedException)
        {
            // Outra rotina de cleanup já concluiu a sessão.
        }
    }

    private MediaSnapshot Update(MediaState state, long? channelId, string? channelName, int attempt, string? errorCode,
        string? mediaKind = null, string? vodContentKind = null, string? sessionId = null,
        double position = 0, double duration = 0, bool seekable = false, bool paused = false)
    {
        var snapshot = new MediaSnapshot(state.ToString().ToLowerInvariant(), channelId, channelName, attempt, errorCode, _activeMode,
            SessionId: sessionId, Position: position, Duration: duration, Seekable: seekable,
            MediaKind: mediaKind, Paused: paused, VodContentKind: vodContentKind);
        lock (_snapshotLock)
        {
            _snapshot = snapshot;
        }
        StateChanged?.Invoke(this, snapshot);
        return snapshot;
    }

    private static HostAuthorization ValidAuthorization(Func<HostAuthorization?>? provider)
    {
        var authorization = provider?.Invoke();
        if (authorization is null || authorization.Permission != "media.publish")
        {
            throw new MediaEngineException("host_authorization_required");
        }
        if (authorization.ExpiresAt <= DateTimeOffset.UtcNow)
        {
            throw new MediaEngineException("host_authorization_expired");
        }
        return authorization;
    }

    private static bool SameContext(HostAuthorization left, HostAuthorization right) =>
        left.RoomCode == right.RoomCode
        && left.TransmissionInstanceId == right.TransmissionInstanceId
        && left.TransmissionRevision == right.TransmissionRevision;

    public async ValueTask DisposeAsync()
    {
        if (_disposed)
        {
            return;
        }
        _disposed = true;
        try
        {
            await StopAsync();
        }
        finally
        {
            _provider.Dispose();
            _gate.Dispose();
        }
    }

    public void Dispose()
    {
        InvalidatePlaybackAndCancel();
        _ = DisposeAsync().AsTask().ContinueWith(
            static task => _ = task.Exception,
            CancellationToken.None,
            TaskContinuationOptions.OnlyOnFaulted | TaskContinuationOptions.ExecuteSynchronously,
            TaskScheduler.Default);
    }
}
