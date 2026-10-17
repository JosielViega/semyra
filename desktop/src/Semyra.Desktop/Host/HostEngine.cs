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
    private nint _nativeVideoHost;
    private bool _disposed;

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
        Func<MpvRuntime, nint, MpvPlayer>? mpvFactory = null)
    {
        _catalogFactory = catalogFactory;
        _mediaFactory = mediaFactory;
        _mpvRuntime = mpvRuntime ?? MpvRuntime.Unavailable();
        _mpvFactory = mpvFactory ?? ((runtime, handle) => new MpvPlayer(runtime, handle));
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
        _authorization = authorization;
        return true;
    }

    public async Task ClearAuthorizationAsync()
    {
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
        MpvReady);

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
        var channel = Catalog().ResolveChannelForPlayback(channelId);
        return await EnsureMpvPlayer().StartAsync(channel, cancellationToken);
    }

    public Task<MediaSnapshot> StartIptvPublishAsync(long channelId, CancellationToken cancellationToken)
    {
        if (_authorization is null || _authorization.ExpiresAt <= DateTimeOffset.UtcNow)
        {
            throw new MediaEngineException("host_authorization_required");
        }
        return Media().StartPublishAsync(channelId, () => _authorization, cancellationToken);
    }

    public async Task<MediaSnapshot> StopIptvMediaAsync()
    {
        if (_mpvPlayer is not null && _mpvPlayer.Snapshot().State != "idle")
        {
            throw new MediaEngineException("playback_session_required");
        }
        return await Media().StopAsync();
    }
    public MediaSnapshot IptvMediaSnapshot() =>
        _mpvPlayer is not null && _mpvPlayer.Snapshot().State != "idle"
            ? _mpvPlayer.Snapshot()
            : _media?.Snapshot() ?? MediaSnapshot.Idle;

    internal async Task<MediaSnapshot> ControlMpvAsync(string sessionId, string command, double? value, CancellationToken cancellationToken)
    {
        var player = _mpvPlayer ?? throw new MediaEngineException("playback_session_changed");
        var snapshot = await player.ControlAsync(sessionId, command, value, cancellationToken);
        if (command == "stop") NativeVideoFullscreenChanged?.Invoke(this, false);
        return snapshot;
    }

    internal MediaSnapshot UpdateMpvGeometry(string sessionId, MpvGeometry geometry)
    {
        var snapshot = _mpvPlayer?.Snapshot() ?? throw new MediaEngineException("playback_session_changed");
        if (!geometry.IsValid || !string.Equals(snapshot.SessionId, sessionId, StringComparison.Ordinal))
            throw new MediaEngineException(!geometry.IsValid ? "invalid_player_geometry" : "playback_session_changed");
        NativeVideoGeometryChanged?.Invoke(this, geometry);
        return snapshot;
    }

    internal MediaSnapshot SetMpvFullscreen(string sessionId, bool fullscreen)
    {
        var snapshot = _mpvPlayer?.SetFullscreen(sessionId, fullscreen)
            ?? throw new MediaEngineException("playback_session_changed");
        NativeVideoFullscreenChanged?.Invoke(this, fullscreen);
        return snapshot;
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
        MediaStateChanged?.Invoke(this, snapshot);
    }

    private void OnMpvPlaybackConfirmed(object? sender, long channelId)
    {
        if (!ReferenceEquals(sender, _mpvPlayer)) return;
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
