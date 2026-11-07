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
    private const int MaximumTransientStatusFailures = 8;
    private static readonly TimeSpan PublishingConfirmationTimeout = TimeSpan.FromSeconds(18);
    private static readonly TimeSpan StatusRequestTimeout = TimeSpan.FromSeconds(3);
    private static readonly TimeSpan StatusPollInterval = TimeSpan.FromMilliseconds(400);
    private readonly Func<long, IptvPlaybackChannel> _resolveChannel;
    private readonly Func<string, long, IptvVodPlaybackTarget>? _resolveVod;
    private readonly IProviderStreamClient _provider;
    private readonly IMediaPipelineFactory _pipelines;
    private readonly Func<TimeSpan, CancellationToken, Task> _delay;
    private readonly IDesktopPublishClient? _publishClient;
    private readonly TimeSpan _ingressCleanupTimeout;
    private readonly int _publishConfirmationMaximumPolls;
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
        Func<string, long, IptvVodPlaybackTarget>? resolveVod = null,
        TimeSpan? ingressCleanupTimeout = null,
        int publishConfirmationMaximumPolls = 45)
    {
        _resolveChannel = resolveChannel;
        _resolveVod = resolveVod;
        Runtime = runtime;
        _provider = provider;
        _pipelines = pipelines;
        _delay = delay ?? Task.Delay;
        _publishClient = publishClient;
        _ingressCleanupTimeout = ingressCleanupTimeout ?? TimeSpan.FromSeconds(5);
        _publishConfirmationMaximumPolls = Math.Max(1, publishConfirmationMaximumPolls);
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
        var diagnosticStage = "vod_authorization";
        try
        {
            attemptAuthorization = ValidAuthorization(authorizationProvider);
            Update(MediaState.Preparing, target.Id, target.Name, 1, null, "vod", target.Kind,
                diagnosticCode: "vod_authorization_validated");
            diagnosticStage = "vod_ingress";
            lease = await _publishClient!.StartAsync(attemptAuthorization, cancellationToken);
            Update(MediaState.Preparing, target.Id, target.Name, 1, null, "vod", target.Kind,
                diagnosticCode: "vod_ingress_created");
            diagnosticStage = "vod_source";
            await using var source = await VodPublishSource.StartAsync(target.StreamUri, cancellationToken);
            Update(MediaState.Preparing, target.Id, target.Name, 1, null, "vod", target.Kind,
                diagnosticCode: "vod_source_ready");
            diagnosticStage = "vod_probe";
            _ = await VodMediaProbe.InspectAsync(Runtime, source.LocalUri, cancellationToken);
            Update(MediaState.Preparing, target.Id, target.Name, 1, null, "vod", target.Kind,
                diagnosticCode: "vod_probe_ready");
            diagnosticStage = "vod_pipeline";
            await using var pipeline = _pipelines.CreateVodPublish(source.LocalUri, lease.WhipEndpoint);
            await pipeline.StartAsync(cancellationToken);
            Update(MediaState.Preparing, target.Id, target.Name, 1, null, "vod", target.Kind,
                diagnosticCode: "vod_pipeline_started");
            var completion = pipeline.WaitForExitAsync(cancellationToken);
            diagnosticStage = "vod_ingress_confirmation";
            await WaitForPublishingAsync(attemptAuthorization, lease.IngressId, completion, cancellationToken);
            var native = await pipeline.SnapshotAsync(cancellationToken);
            lock (_snapshotLock)
            {
                _activeVodPipeline = pipeline;
                _activeVodAuthorization = attemptAuthorization;
            }
            var streaming = Update(MediaState.Streaming, target.Id, target.Name, 1, null, "vod", target.Kind,
                native.SessionId, native.PositionMs / 1000d, native.DurationMs / 1000d, native.Seekable, native.State == "paused",
                "vod_ingress_publishing");
            ready.TrySetResult(streaming);
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
            var code = ClassifyVodFailure(exception, diagnosticStage);
            var failed = Update(MediaState.Failed, target.Id, target.Name, 1, code, "vod", target.Kind,
                diagnosticCode: diagnosticStage + "_failed");
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
                    await StopIngressAsync(
                        latest is not null && SameContext(latest, attemptAuthorization) ? latest : attemptAuthorization,
                        lease.IngressId);
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
            var diagnosticStage = "live_authorization";
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
                        diagnosticStage = "live_ingress";
                        publishLease = await _publishClient!.StartAsync(attemptAuthorization, attemptToken);
                    }
                    diagnosticStage = "live_provider";
                    await using var providerStream = await _provider.OpenAsync(channel.StreamUri, attemptToken);
                    diagnosticStage = "live_validation";
                    var initialBuffer = await MpegTsValidator.ReadAndValidateAsync(providerStream, attemptToken);
                    diagnosticStage = "live_pipeline";
                    await using var pipeline = _pipelines.CreatePublish(publishLease!.WhipEndpoint);
                    await pipeline.StartAsync(initialBuffer, attemptToken);
                    var pump = pipeline.PumpAsync(providerStream, attemptToken);
                    diagnosticStage = "live_ingress_confirmation";
                    await WaitForPublishingAsync(attemptAuthorization!, publishLease.IngressId, pump, attemptToken);
                    var streaming = Update(MediaState.Streaming, channel.Id, channel.Name, attempt, null,
                        diagnosticCode: "live_ingress_publishing");
                    ready.TrySetResult(streaming);
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
                            await StopIngressAsync(
                                latest is not null && SameContext(latest, attemptAuthorization) ? latest : attemptAuthorization,
                                publishLease.IngressId);
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
                var code = ClassifyLiveFailure(exception, diagnosticStage);
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

    private async Task StopIngressAsync(HostAuthorization authorization, string ingressId)
    {
        using var timeout = new CancellationTokenSource(_ingressCleanupTimeout);
        await _publishClient!.StopAsync(authorization, ingressId, timeout.Token);
    }

    private async Task WaitForPublishingAsync(
        HostAuthorization authorization,
        string ingressId,
        Task pipelineLifetime,
        CancellationToken cancellationToken)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(PublishingConfirmationTimeout);
        var transientFailures = 0;
        try
        {
            for (var poll = 0; poll < _publishConfirmationMaximumPolls; poll++)
            {
                if (pipelineLifetime.IsCompleted)
                {
                    await pipelineLifetime;
                    throw new MediaEngineException("pipeline_exited");
                }
                DesktopPublishStatus status;
                using var requestTimeout = CancellationTokenSource.CreateLinkedTokenSource(timeout.Token);
                requestTimeout.CancelAfter(StatusRequestTimeout);
                try
                {
                    status = await _publishClient!.StatusAsync(authorization, ingressId, requestTimeout.Token);
                    transientFailures = 0;
                }
                catch (OperationCanceledException) when (!timeout.IsCancellationRequested)
                {
                    if (++transientFailures >= MaximumTransientStatusFailures)
                        throw new MediaEngineException("ingress_status_request_failed");
                    await _delay(StatusPollInterval, timeout.Token);
                    continue;
                }
                catch (MediaEngineException exception) when (
                    (exception.Code is "ingress_not_found" or "ingress_status_request_failed")
                    && ++transientFailures < MaximumTransientStatusFailures)
                {
                    await _delay(StatusPollInterval, timeout.Token);
                    continue;
                }

                if (status.State == "publishing") return;
                if (status.State == "error") throw new MediaEngineException("ingress_publish_error");
                if (status.State == "complete") throw new MediaEngineException("whip_publish_failed");
                await _delay(StatusPollInterval, timeout.Token);
            }
            throw new MediaEngineException("ingress_publish_confirmation_timeout");
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            throw new MediaEngineException("ingress_publish_confirmation_timeout");
        }
    }

    internal static string ClassifyVodFailure(Exception exception, string stage)
    {
        if (exception is MediaEngineException media) return media.Code;
        return stage switch
        {
            "vod_ingress" => "ingress_create_failed",
            "vod_source" => "vod_source_failed",
            "vod_probe" => "vod_probe_failed",
            "vod_pipeline" => "pipeline_start_failed",
            "vod_ingress_confirmation" => "ingress_publish_confirmation_failed",
            _ => "media_publish_failed",
        };
    }

    internal static string ClassifyLiveFailure(Exception exception, string stage)
    {
        if (exception is MediaEngineException media) return media.Code;
        return stage switch
        {
            "live_ingress" => "ingress_create_failed",
            "live_provider" => "provider_connect_failed",
            "live_validation" => "provider_stream_invalid",
            "live_pipeline" => "pipeline_start_failed",
            "live_ingress_confirmation" => "ingress_publish_confirmation_failed",
            _ => "media_publish_failed",
        };
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
        double position = 0, double duration = 0, bool seekable = false, bool paused = false,
        string? diagnosticCode = null)
    {
        var snapshot = new MediaSnapshot(state.ToString().ToLowerInvariant(), channelId, channelName, attempt, errorCode, _activeMode,
            SessionId: sessionId, Position: position, Duration: duration, Seekable: seekable,
            MediaKind: mediaKind, Paused: paused, VodContentKind: vodContentKind, DiagnosticCode: diagnosticCode);
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
