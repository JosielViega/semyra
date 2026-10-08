using Semyra.Desktop.Iptv;
using Semyra.Desktop.Host;
using Semyra.Desktop.Desktop;
using System.IO;

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
    private LocalPlaybackSession? _localPlayback;
    private MediaSnapshot _snapshot = MediaSnapshot.Idle;
    private bool _disposed;

    internal MediaEngine(
        Func<long, IptvPlaybackChannel> resolveChannel,
        GStreamerRuntime runtime,
        IProviderStreamClient provider,
        IMediaPipelineFactory pipelines,
        Func<TimeSpan, CancellationToken, Task>? delay = null,
        IDesktopPublishClient? publishClient = null)
    {
        _resolveChannel = resolveChannel;
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
            publishClient: new DesktopPublishClient(() => SemyraWebConfiguration.Load().BaseUri));
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
        return (await StartCoreAsync(channelId, "local", null, cancellationToken)).Snapshot;
    }

    public async Task<MediaSnapshot> StartPublishAsync(long channelId, Func<HostAuthorization?> authorizationProvider, CancellationToken cancellationToken = default)
    {
        if (!Runtime.IsWhipAvailable || _publishClient is null)
        {
            throw new MediaEngineException("whip_runtime_unavailable");
        }
        return (await StartCoreAsync(channelId, "publish", authorizationProvider, cancellationToken)).Snapshot;
    }

    public async Task<LocalPlaybackStartResult> StartLocalViewAsync(long channelId, CancellationToken cancellationToken = default)
    {
        if (!Runtime.IsLocalViewAvailable)
        {
            throw new MediaEngineException("local_view_runtime_unavailable");
        }

        LocalPlaybackSession? created = null;
        try
        {
            created = LocalPlaybackSession.Create();
            var start = await StartCoreAsync(channelId, "view", null, cancellationToken, created);
            var snapshot = start.Snapshot;
            if (snapshot.State != "streaming")
            {
                await StopAsync();
                throw new MediaEngineException(snapshot.LastErrorCode ?? "local_view_unavailable");
            }
            var playback = start.Playback
                ?? throw new MediaEngineException("local_view_unavailable");
            return new LocalPlaybackStartResult(snapshot, playback.PlaybackUrl);
        }
        catch
        {
            if (created is not null && ReferenceEquals(created, _localPlayback))
            {
                await StopAsync();
            }
            else
            {
                created?.Dispose();
            }
            throw;
        }
    }

    private async Task<(MediaSnapshot Snapshot, LocalPlaybackSession? Playback)> StartCoreAsync(
        long channelId,
        string mode,
        Func<HostAuthorization?>? authorizationProvider,
        CancellationToken cancellationToken,
        LocalPlaybackSession? localPlayback = null)
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
                localPlayback?.Dispose();
                return (Snapshot(), _localPlayback);
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
            _localPlayback = localPlayback;
            _sessionCancellation = new CancellationTokenSource();
            ready = new TaskCompletionSource<MediaSnapshot>(TaskCreationOptions.RunContinuationsAsynchronously);
            Update(MediaState.Preparing, channel.Id, channel.Name, 1, null);
            _sessionTask = RunSessionAsync(channel, mode, authorizationProvider, localPlayback, _sessionCancellation.Token, ready);
        }
        finally
        {
            _gate.Release();
        }

        var snapshot = await ready.Task.WaitAsync(cancellationToken);
        return (snapshot, localPlayback);
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
        LocalPlaybackSession? localPlayback,
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
                    if (mode == "view")
                    {
                        localPlayback!.PrepareAttempt();
                    }
                    await using var pipeline = mode switch
                    {
                        "publish" => _pipelines.CreatePublish(publishLease!.WhipEndpoint),
                        "view" => _pipelines.CreateLocalView(localPlayback!.DirectoryPath),
                        _ => _pipelines.Create(),
                    };
                    await pipeline.StartAsync(initialBuffer, attemptToken);
                    Task pump = Task.CompletedTask;
                    if (mode == "view")
                    {
                        pump = pipeline.PumpAsync(providerStream, attemptToken);
                        await WaitForPlaylistAsync(localPlayback!.DirectoryPath, pump, attemptToken);
                    }
                    var streaming = Update(MediaState.Streaming, channel.Id, channel.Name, attempt, null);
                    ready.TrySetResult(streaming);
                    if (mode != "view")
                    {
                        pump = pipeline.PumpAsync(providerStream, attemptToken);
                    }
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

    private async Task WaitForPlaylistAsync(string directory, Task pump, CancellationToken cancellationToken)
    {
        var playlist = Path.Combine(directory, "index.m3u8");
        for (var check = 0; check < 80; check++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (File.Exists(playlist) && new FileInfo(playlist).Length > 0)
            {
                return;
            }
            if (pump.IsCompleted)
            {
                await pump;
                throw new MediaEngineException("pipeline_exited");
            }
            await _delay(TimeSpan.FromMilliseconds(100), cancellationToken);
        }
        throw new MediaEngineException("local_view_playlist_unavailable");
    }

    private async Task StopSessionLockedAsync()
    {
        var cancellation = _sessionCancellation;
        var task = _sessionTask;
        _sessionCancellation = null;
        _sessionTask = null;
        _activeChannelId = null;
        _activeMode = "local";
        var localPlayback = _localPlayback;
        _localPlayback = null;
        localPlayback?.Dispose();
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
        Interlocked.Exchange(ref _localPlayback, null)?.Dispose();
        try
        {
            Volatile.Read(ref _sessionCancellation)?.Cancel();
        }
        catch (ObjectDisposedException)
        {
            // Outra rotina de cleanup já concluiu a sessão.
        }
    }

    public bool TryReadLocalPlayback(Uri requestUri, string method, out LocalPlaybackResource? resource)
    {
        resource = null;
        var playback = _localPlayback;
        return playback is not null && playback.TryRead(requestUri, method, out resource);
    }

    public Task<LocalPlaybackResource?> ReadLocalPlaybackAsync(Uri requestUri, string method, CancellationToken cancellationToken = default)
    {
        var playback = _localPlayback;
        return playback is null
            ? Task.FromResult<LocalPlaybackResource?>(null)
            : playback.ReadAsync(requestUri, method, cancellationToken);
    }

    internal LocalPlaybackRequestKind ClassifyLocalPlaybackRequest(Uri requestUri, string method)
    {
        var playback = _localPlayback;
        return playback is null
            ? LocalPlaybackSession.IsReservedNamespace(requestUri)
                ? LocalPlaybackRequestKind.PlaybackInvalid
                : LocalPlaybackRequestKind.NotPlayback
            : playback.ClassifyRequest(requestUri, method);
    }

    private MediaSnapshot Update(MediaState state, long? channelId, string? channelName, int attempt, string? errorCode)
    {
        var snapshot = new MediaSnapshot(state.ToString().ToLowerInvariant(), channelId, channelName, attempt, errorCode, _activeMode);
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
