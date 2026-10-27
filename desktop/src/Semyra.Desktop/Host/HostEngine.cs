using System.Security.Cryptography;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Host;

public sealed class HostEngine : IDisposable, IAsyncDisposable
{
    private HostAuthorization? _authorization;
    private IptvCatalogService? _iptv;
    private MediaEngine? _media;
    private string? _activeProfileId;
    private string? _accountContextId;
    private readonly Func<string, IptvCatalogService> _catalogFactory;
    private readonly Func<IptvCatalogService, MediaEngine> _mediaFactory;
    private readonly MpvRuntime _mpvRuntime;
    private readonly Func<MpvRuntime, nint, MpvPlayer> _mpvFactory;
    private readonly SemaphoreSlim _lifecycleGate = new(1, 1);
    private MpvPlayer? _mpvPlayer;
    private long? _recentChannelId;
    private IptvVodPlaybackTarget? _activeVod;
    private string? _activeVodSessionId;
    private CancellationTokenSource? _vodProgressCancellation;
    private Task? _vodProgressTask;
    private long _vodGeneration;
    private readonly TimeSpan _vodProgressInterval;
    private nint _nativeVideoHost;
    private bool _disposed;
    private CancellationTokenSource? _authorizationClearCancellation;

    public HostEngine()
        : this(
            IptvCatalogService.CreateDefault,
            catalog => MediaEngine.CreateDefault(catalog, GStreamerRuntime.DiscoverDefault()),
            MpvRuntime.DiscoverDefault())
    {
    }

    internal HostEngine(
        Func<string, IptvCatalogService> catalogFactory,
        Func<IptvCatalogService, MediaEngine> mediaFactory,
        MpvRuntime? mpvRuntime = null,
        Func<MpvRuntime, nint, MpvPlayer>? mpvFactory = null,
        TimeSpan? vodProgressInterval = null)
    {
        _catalogFactory = catalogFactory;
        _mediaFactory = mediaFactory;
        _mpvRuntime = mpvRuntime ?? MpvRuntime.Unavailable();
        _mpvFactory = mpvFactory ?? ((runtime, handle) => new MpvPlayer(runtime, handle));
        _vodProgressInterval = vodProgressInterval ?? TimeSpan.FromSeconds(30);
        if (_vodProgressInterval <= TimeSpan.Zero) throw new ArgumentOutOfRangeException(nameof(vodProgressInterval));
    }

    internal HostEngine(IptvCatalogService catalog, MediaEngine? media = null)
        : this(_ => catalog, value => media ?? MediaEngine.CreateDefault(value, GStreamerRuntime.DiscoverDefault()))
    {
    }

    internal HostEngine(MediaEngine media)
        : this(_ => throw new InvalidOperationException("Test catalog unavailable."), _ => media)
    {
        _media = media;
        _activeProfileId = new string('f', 64);
        _accountContextId = new string('e', 32);
    }

    public HostEngineState State { get; private set; } = HostEngineState.Stopped;
    public event EventHandler<MediaSnapshot>? MediaStateChanged;
    public event EventHandler<IptvVodProgressChanged>? VodProgressChanged;
    internal event EventHandler<MpvGeometry>? NativeVideoGeometryChanged;
    internal event EventHandler<bool>? NativeVideoVisibilityChanged;
    internal event EventHandler<bool>? NativeVideoFullscreenChanged;
    internal string? CurrentAccountContextId => _accountContextId;
    internal MpvRuntime MpvRuntime => _mpvRuntime;
    internal LocalPlaybackEngine LocalPlaybackEngine => MpvReady
        ? LocalPlaybackEngine.Mpv
        : LocalPlaybackEngine.Unavailable;
    private bool MpvReady => _mpvRuntime.Available && _nativeVideoHost != 0;

    internal void SetNativeVideoHost(nint handle)
    {
        if (handle == 0) throw new ArgumentOutOfRangeException(nameof(handle));
        _nativeVideoHost = handle;
    }

    public void Start() => State = HostEngineState.Ready;

    public async Task StopAsync()
    {
        State = HostEngineState.Stopped;
        await ClearAccountAsync();
    }

    public async Task<string> ActivateAccountAsync(string profileId)
    {
        if (State != HostEngineState.Ready || !ValidProfileId(profileId))
        {
            throw new IptvValidationException("Perfil local inválido.");
        }
        if (!string.Equals(_activeProfileId, profileId, StringComparison.Ordinal))
        {
            FenceActiveAccount();
        }
        await _lifecycleGate.WaitAsync();
        try
        {
            if (!string.Equals(_activeProfileId, profileId, StringComparison.Ordinal))
            {
                await ClearAccountCoreAsync();
                IptvCatalogService? catalog = null;
                MediaEngine? media = null;
                try
                {
                    catalog = _catalogFactory(profileId);
                    catalog.Initialize();
                    media = _mediaFactory(catalog);
                    media.StateChanged += OnMediaStateChanged;
                    _iptv = catalog;
                    _media = media;
                    _activeProfileId = profileId;
                }
                catch
                {
                    if (media is not null)
                    {
                        media.StateChanged -= OnMediaStateChanged;
                        await media.DisposeAsync();
                    }
                    catalog?.Dispose();
                    throw;
                }
            }
            _accountContextId = Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
            return _accountContextId;
        }
        finally
        {
            _lifecycleGate.Release();
        }
    }

    public async Task ClearAccountAsync()
    {
        FenceActiveAccount();
        await _lifecycleGate.WaitAsync();
        try
        {
            await ClearAccountCoreAsync();
        }
        finally
        {
            _lifecycleGate.Release();
        }
    }

    private async Task ClearAccountCoreAsync()
    {
        FlushVodProgress();
        CancelVodProgressLoop();
        var media = _media;
        var mpv = _mpvPlayer;
        var catalog = _iptv;
        _accountContextId = null;
        _authorization = null;
        _media = null;
        _mpvPlayer = null;
        _iptv = null;
        _activeProfileId = null;
        if (media is not null)
        {
            media.StateChanged -= OnMediaStateChanged;
            media.InvalidatePlaybackAndCancel();
        }
        if (mpv is not null)
        {
            NativeVideoVisibilityChanged?.Invoke(this, false);
            NativeVideoFullscreenChanged?.Invoke(this, false);
            mpv.StateChanged -= OnMpvStateChanged;
            mpv.VisibilityChanged -= OnMpvVisibilityChanged;
            mpv.PlaybackConfirmed -= OnMpvPlaybackConfirmed;
        }
        try
        {
            if (mpv is not null) await mpv.DisposeAsync();
            if (media is not null)
            {
                await media.DisposeAsync();
            }
        }
        finally
        {
            catalog?.Dispose();
        }
    }

    private void FenceActiveAccount()
    {
        FlushVodProgress();
        _accountContextId = null;
        _authorization = null;
        _media?.InvalidatePlaybackAndCancel();
    }

    public bool IsCurrentAccountContext(string? contextId) =>
        _accountContextId is not null && contextId is not null && CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(_accountContextId),
            System.Text.Encoding.ASCII.GetBytes(contextId));

    public bool Authorize(HostAuthorization authorization)
    {
        if (State != HostEngineState.Ready || authorization.ExpiresAt <= DateTimeOffset.UtcNow)
        {
            return false;
        }
        CancelScheduledAuthorizationClear();
        _authorization = authorization;
        return true;
    }

    public void ScheduleAuthorizationClear(TimeSpan delay)
    {
        if (delay < TimeSpan.Zero) throw new ArgumentOutOfRangeException(nameof(delay));
        CancelScheduledAuthorizationClear();
        var cancellation = new CancellationTokenSource();
        _authorizationClearCancellation = cancellation;
        _ = ClearAuthorizationAfterAsync(delay, cancellation);
    }

    private async Task ClearAuthorizationAfterAsync(TimeSpan delay, CancellationTokenSource cancellation)
    {
        try
        {
            await Task.Delay(delay, cancellation.Token);
            if (ReferenceEquals(_authorizationClearCancellation, cancellation))
            {
                _authorizationClearCancellation = null;
                await ClearAuthorizationAsync();
            }
        }
        catch (OperationCanceledException) { }
        finally
        {
            cancellation.Dispose();
        }
    }

    private void CancelScheduledAuthorizationClear()
    {
        var cancellation = Interlocked.Exchange(ref _authorizationClearCancellation, null);
        cancellation?.Cancel();
    }

    public async Task ClearAuthorizationAsync()
    {
        CancelScheduledAuthorizationClear();
        var media = _media?.Snapshot().Mode == "publish" ? _media : null;
        _authorization = null;
        if (media is not null)
        {
            media.InvalidatePlaybackAndCancel();
            await media.StopAsync();
        }
    }

    public HostEngineSnapshot Snapshot() => HostEngineSnapshot.Create(
        State,
        _authorization,
        _accountContextId is not null,
        _media?.Runtime.IsWhipAvailable == true,
        MpvReady,
        _media?.Runtime.IsVodPublishAvailable == true);

    public IReadOnlyList<IptvSourceSummary> ListIptvSources() => Catalog().ListSources();
    public IptvSourceSummary AddIptvUrl(string name, string location) => Catalog().AddUrlSource(name, location);
    public Task<IptvSourceSummary> AddIptvXtreamAsync(
        string name,
        string baseUrl,
        string username,
        string password,
        CancellationToken cancellationToken) =>
        Catalog().AddXtreamSourceAsync(name, baseUrl, username, password, cancellationToken);
    public IptvSourceSummary AddIptvFile(string path) => Catalog().AddFileSource(path);
    public bool RemoveIptvSource(long sourceId) => Catalog().RemoveSource(sourceId);
    public Task<IptvSourceSummary> RefreshIptvSourceAsync(long sourceId, CancellationToken cancellationToken) => Catalog().RefreshAsync(sourceId, cancellationToken);
    public Task<IptvSourceSummary> SyncIptvVodAsync(long sourceId, CancellationToken cancellationToken) => Catalog().SyncVodAsync(sourceId, cancellationToken);
    public IReadOnlyList<IptvVodCategorySummary> GetIptvVodCategories(long sourceId, IptvVodMediaType type) => Catalog().GetVodCategories(sourceId, type);
    public IReadOnlyList<IptvContinueMovie> GetContinueWatchingMovies(long sourceId) => Catalog().GetContinueWatchingMovies(sourceId);
    public IReadOnlyList<IptvContinueSeries> GetContinueWatchingSeries(long sourceId) => Catalog().GetContinueWatchingSeries(sourceId);
    public bool DismissContinueWatching(long sourceId, string kind, long mediaId) => Catalog().DismissContinueWatching(sourceId, kind, mediaId);
    public IptvVodSearchResult<IptvMovieSummary> SearchIptvMovies(long sourceId, string? query, string? category, int offset, int limit) => Catalog().SearchMovies(sourceId, query, category, offset, limit);
    public IptvVodSearchResult<IptvSeriesSummary> SearchIptvSeries(long sourceId, string? query, string? category, int offset, int limit) => Catalog().SearchSeries(sourceId, query, category, offset, limit);
    public Task<IptvSeriesDetails> GetIptvSeriesDetailsAsync(long seriesId, bool refresh, CancellationToken cancellationToken) => Catalog().GetSeriesDetailsAsync(seriesId, refresh, cancellationToken);
    public IReadOnlyList<string> GetIptvGroups(long sourceId) => Catalog().GetGroups(sourceId);
    public IptvChannelSearchResult SearchIptvChannels(long sourceId, string? query, string? group, int offset, int limit) => Catalog().SearchChannels(sourceId, query, group, offset, limit);
    public IptvChannelSearchResult ListIptvFavorites(long sourceId, int offset, int limit) => Catalog().ListFavorites(sourceId, offset, limit);
    public IReadOnlyList<IptvRecentSummary> ListIptvRecents(long sourceId) => Catalog().ListRecents(sourceId);
    public bool RemoveIptvRecent(long sourceId, long recentId) => Catalog().RemoveRecent(sourceId, recentId);
    public int ClearIptvRecents(long sourceId) => Catalog().ClearRecents(sourceId);
    public bool SetIptvFavorite(long channelId, bool favorite) => Catalog().SetFavorite(channelId, favorite);
    public async Task<MpvPlaybackStartResult> StartIptvLocalViewAsync(long channelId, CancellationToken cancellationToken)
    {
        if (!MpvReady)
        {
            throw new MediaEngineException("mpv_runtime_unavailable");
        }
        FlushVodProgress(); CancelVodProgressLoop();
        var channel = Catalog().ResolveChannelForPlayback(channelId);
        _recentChannelId = channel.Id;
        return await EnsureMpvPlayer().StartAsync(channel, cancellationToken);
    }

    public async Task<MpvPlaybackStartResult> StartIptvVodViewAsync(string kind, long mediaId, CancellationToken cancellationToken)
    {
        if (!MpvReady) throw new MediaEngineException("mpv_runtime_unavailable");
        FlushVodProgress(); CancelVodProgressLoop();
        _recentChannelId = null;
        IptvVodPlaybackTarget target;
        try { target = Catalog().ResolveVodForPlayback(kind, mediaId); }
        catch (IptvValidationException) { throw new MediaEngineException("vod_not_found"); }
        return await StartVodTargetAsync(target, cancellationToken);
    }

    public async Task<MpvPlaybackStartResult> NavigateIptvEpisodeAsync(string direction, CancellationToken cancellationToken)
    {
        if (_activeVod is not { Kind: "episode" } current) throw new MediaEngineException("vod_navigation_unavailable");
        var preserveFullscreen = _mpvPlayer?.Snapshot().Fullscreen == true;
        FlushVodProgress(); CancelVodProgressLoop();
        var target = Catalog().NavigateEpisode(current.SourceId, current.StableKey, direction) ?? throw new MediaEngineException("vod_navigation_unavailable");
        return await StartVodTargetAsync(target, cancellationToken, preserveFullscreen);
    }

    private async Task<MpvPlaybackStartResult> StartVodTargetAsync(
        IptvVodPlaybackTarget target,
        CancellationToken cancellationToken,
        bool preserveFullscreen = false,
        bool announceState = false)
    {
        var media = new IptvPlaybackChannel(target.Id, target.SourceId, target.Name, null, target.StreamUri);
        var result = await EnsureMpvPlayer().StartAsync(media, cancellationToken, target.ResumePosition, preserveFullscreen);
        _activeVod = target; _activeVodSessionId = result.SessionId;
        StartVodProgressLoop(result.SessionId);
        var enriched = result with { Snapshot = EnrichVod(result.Snapshot) };
        if (announceState) MediaStateChanged?.Invoke(this, enriched.Snapshot);
        return enriched;
    }

    public async Task<MediaSnapshot> StartIptvPublishAsync(long channelId, CancellationToken cancellationToken)
    {
        if (_authorization is null || _authorization.ExpiresAt <= DateTimeOffset.UtcNow)
        {
            throw new MediaEngineException("host_authorization_required");
        }
        FlushVodProgress();
        CancelVodProgressLoop();
        if (_mpvPlayer is not null && _mpvPlayer.Snapshot().State != "idle")
        {
            await _mpvPlayer.StopAsync();
        }
        return await Media().StartPublishAsync(channelId, () => _authorization, cancellationToken);
    }

    public async Task<MediaSnapshot> StartIptvVodPublishAsync(string kind, long mediaId, CancellationToken cancellationToken)
    {
        if (_authorization is null || _authorization.ExpiresAt <= DateTimeOffset.UtcNow)
        {
            throw new MediaEngineException("host_authorization_required");
        }
        if (kind is not ("movie" or "episode")) throw new MediaEngineException("invalid_media_kind");
        FlushVodProgress();
        CancelVodProgressLoop();
        if (_mpvPlayer is not null && _mpvPlayer.Snapshot().State != "idle") await _mpvPlayer.StopAsync();
        return await Media().StartVodPublishAsync(kind, mediaId, () => _authorization, cancellationToken);
    }

    public async Task<MediaSnapshot> StopIptvMediaAsync()
    {
        if (_mpvPlayer is not null && _mpvPlayer.Snapshot().State != "idle")
        {
            throw new MediaEngineException("playback_session_required");
        }
        return await Media().StopAsync();
    }
    public MediaSnapshot IptvMediaSnapshot() => EnrichVod(
        _mpvPlayer is not null && _mpvPlayer.Snapshot().State != "idle"
            ? _mpvPlayer.Snapshot()
            : _media?.Snapshot() ?? MediaSnapshot.Idle);

    public Task<MediaSnapshot> ControlIptvVodPublishAsync(
        string action,
        long? positionMs,
        string transmissionInstanceId,
        long transmissionRevision,
        CancellationToken cancellationToken)
    {
        var authorization = _authorization;
        if (authorization is null || authorization.ExpiresAt <= DateTimeOffset.UtcNow)
            throw new MediaEngineException("host_authorization_required");
        if (!string.Equals(authorization.TransmissionInstanceId, transmissionInstanceId, StringComparison.Ordinal)
            || authorization.TransmissionRevision != transmissionRevision)
            throw new MediaEngineException("transmission_changed");
        return Media().ControlVodPublishAsync(action, positionMs, authorization, cancellationToken);
    }

    internal async Task<MediaSnapshot> ControlMpvAsync(string sessionId, string command, double? value, CancellationToken cancellationToken)
    {
        var player = _mpvPlayer ?? throw new MediaEngineException("playback_session_changed");
        if (command == "stop") { FlushVodProgress(); CancelVodProgressLoop(); }
        var snapshot = await player.ControlAsync(sessionId, command, value, cancellationToken);
        if (command is "pause" or "seek-absolute" or "seek-relative") FlushVodProgress(snapshot);
        if (command == "stop") NativeVideoFullscreenChanged?.Invoke(this, false);
        return EnrichVod(snapshot);
    }

    internal MediaSnapshot UpdateMpvGeometry(string sessionId, MpvGeometry geometry)
    {
        var snapshot = _mpvPlayer?.Snapshot() ?? throw new MediaEngineException("playback_session_changed");
        if (!geometry.IsValid || !string.Equals(snapshot.SessionId, sessionId, StringComparison.Ordinal))
            throw new MediaEngineException(!geometry.IsValid ? "invalid_player_geometry" : "playback_session_changed");
        NativeVideoGeometryChanged?.Invoke(this, geometry);
        return EnrichVod(snapshot);
    }

    internal MediaSnapshot SetMpvFullscreen(string sessionId, bool fullscreen)
    {
        var snapshot = _mpvPlayer?.SetFullscreen(sessionId, fullscreen)
            ?? throw new MediaEngineException("playback_session_changed");
        NativeVideoFullscreenChanged?.Invoke(this, fullscreen);
        return EnrichVod(snapshot);
    }
    private IptvCatalogService Catalog() => State == HostEngineState.Ready && _iptv is not null
        ? _iptv : throw new IptvValidationException("O catálogo IPTV local não está disponível.");
    private MediaEngine Media() => State == HostEngineState.Ready && _media is not null
        ? _media : throw new MediaEngineException("media_runtime_unavailable");
    internal void OnMediaStateChanged(object? sender, MediaSnapshot snapshot)
    {
        MediaStateChanged?.Invoke(this, snapshot);
    }

    private MpvPlayer EnsureMpvPlayer()
    {
        if (_mpvPlayer is not null) return _mpvPlayer;
        var player = _mpvFactory(_mpvRuntime, _nativeVideoHost);
        player.StateChanged += OnMpvStateChanged;
        player.VisibilityChanged += OnMpvVisibilityChanged;
        player.PlaybackConfirmed += OnMpvPlaybackConfirmed;
        _mpvPlayer = player;
        return player;
    }

    private void OnMpvStateChanged(object? sender, MediaSnapshot snapshot)
    {
        if (!ReferenceEquals(sender, _mpvPlayer)) return;
        var enriched = EnrichVod(snapshot);
        IptvVodPlaybackTarget? endedEpisode = null;
        bool preserveFullscreen = false;
        long expectedGeneration = 0;
        if (snapshot.State == "ended")
        {
            var active = _activeVod;
            var activeSession = _activeVodSessionId;
            FlushVodProgress(snapshot);
            if (active is { Kind: "episode" } && string.Equals(snapshot.SessionId, activeSession, StringComparison.Ordinal))
            {
                endedEpisode = active;
                preserveFullscreen = snapshot.Fullscreen;
            }
            CancelVodProgressLoop();
            expectedGeneration = _vodGeneration;
        }
        MediaStateChanged?.Invoke(this, enriched);
        if (endedEpisode is not null)
        {
            _ = AutoplayNextEpisodeAsync(endedEpisode, preserveFullscreen, expectedGeneration);
        }
    }

    private async Task AutoplayNextEpisodeAsync(
        IptvVodPlaybackTarget completed,
        bool preserveFullscreen,
        long expectedGeneration)
    {
        await Task.Yield();
        if (_vodGeneration != expectedGeneration || _activeVod is not null || State != HostEngineState.Ready) return;
        IptvVodPlaybackTarget? next;
        try { next = Catalog().NavigateEpisode(completed.SourceId, completed.StableKey, "next"); }
        catch { return; }
        if (next is null || _vodGeneration != expectedGeneration || _activeVod is not null) return;
        try { await StartVodTargetAsync(next, CancellationToken.None, preserveFullscreen, announceState: true); }
        catch { }
    }

    private MediaSnapshot EnrichVod(MediaSnapshot snapshot)
    {
        if (_activeVod is not { } active || !string.Equals(snapshot.SessionId, _activeVodSessionId, StringComparison.Ordinal)) return snapshot;
        if (active.Kind != "episode") return snapshot with { VodContentKind = "movie" };
        var nav = Catalog().EpisodeNavigationAvailability(active.SourceId, active.StableKey);
        return snapshot with { VodContentKind = "episode", CanPreviousEpisode = nav.Previous, CanNextEpisode = nav.Next };
    }

    private void FlushVodProgress(MediaSnapshot? snapshot = null)
    {
        var active = _activeVod; var session = _activeVodSessionId; var catalog = _iptv;
        if (active is null || session is null || catalog is null) return;
        var current = snapshot ?? _mpvPlayer?.Snapshot();
        if (current is null || !string.Equals(current.SessionId, session, StringComparison.Ordinal)) return;
        try
        {
            var item = new IptvVodPlaybackItem(active.Id, active.SourceId, active.Name, string.Empty, null,
                active.Kind, active.StableKey, active.SeriesStableKey, active.SeriesId, active.SeasonNumber, active.EpisodeNumber);
            if (!catalog.SaveVodProgress(item, current.Position, current.Duration)) return;
            var position = Math.Clamp(current.Position, 0, current.Duration);
            VodProgressChanged?.Invoke(this, new IptvVodProgressChanged(
                active.SourceId,
                active.Kind == "movie" ? "movie" : "episode",
                active.Id,
                active.SeriesId,
                position,
                current.Duration,
                current.Duration > 0 ? Math.Clamp(position / current.Duration * 100, 0, 100) : 0,
                IptvStore.IsCompleted(position, current.Duration),
                active.SeasonNumber,
                active.EpisodeNumber,
                session));
        }
        catch { }
    }

    private void StartVodProgressLoop(string sessionId)
    {
        var generation = ++_vodGeneration; var cancellation = new CancellationTokenSource(); _vodProgressCancellation = cancellation;
        _vodProgressTask = Task.Run(async () =>
        {
            using var timer = new PeriodicTimer(_vodProgressInterval);
            try { while (await timer.WaitForNextTickAsync(cancellation.Token)) { if (generation != _vodGeneration || _activeVodSessionId != sessionId) return; FlushVodProgress(); } }
            catch (OperationCanceledException) { }
        });
    }

    private void CancelVodProgressLoop()
    {
        _vodGeneration++; _vodProgressCancellation?.Cancel(); _vodProgressCancellation?.Dispose(); _vodProgressCancellation = null; _vodProgressTask = null; _activeVod = null; _activeVodSessionId = null;
    }

    private void OnMpvPlaybackConfirmed(object? sender, long channelId)
    {
        if (!ReferenceEquals(sender, _mpvPlayer)) return;
        if (_recentChannelId != channelId) return;
        try { _iptv?.RecordRecent(channelId); } catch { }
    }

    private void OnMpvVisibilityChanged(object? sender, bool visible)
    {
        if (ReferenceEquals(sender, _mpvPlayer)) NativeVideoVisibilityChanged?.Invoke(this, visible);
    }
    private static bool ValidProfileId(string value) => value.Length == 64 && value.All(character => character is >= 'a' and <= 'f' or >= '0' and <= '9');

    public async ValueTask DisposeAsync()
    {
        if (_disposed)
        {
            return;
        }
        _disposed = true;
        CancelScheduledAuthorizationClear();
        try
        {
            await StopAsync();
        }
        finally
        {
            _lifecycleGate.Dispose();
        }
    }

    public void Dispose()
    {
        _accountContextId = null;
        _authorization = null;
        _media?.InvalidatePlaybackAndCancel();
        _ = DisposeAsync().AsTask().ContinueWith(
            static task => _ = task.Exception,
            CancellationToken.None,
            TaskContinuationOptions.OnlyOnFaulted | TaskContinuationOptions.ExecuteSynchronously,
            TaskScheduler.Default);
    }
}
