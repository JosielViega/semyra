using System.Security.Cryptography;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Host;

public sealed class HostEngine : IDisposable
{
    private HostAuthorization? _authorization;
    private IptvCatalogService? _iptv;
    private MediaEngine? _media;
    private string? _activeProfileId;
    private string? _accountContextId;
    private readonly Func<string, IptvCatalogService> _catalogFactory;
    private readonly Func<IptvCatalogService, MediaEngine> _mediaFactory;

    public HostEngine()
        : this(IptvCatalogService.CreateDefault, catalog => MediaEngine.CreateDefault(catalog, GStreamerRuntime.DiscoverDefault()))
    {
    }

    internal HostEngine(Func<string, IptvCatalogService> catalogFactory, Func<IptvCatalogService, MediaEngine> mediaFactory)
    {
        _catalogFactory = catalogFactory;
        _mediaFactory = mediaFactory;
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
    internal string? CurrentAccountContextId => _accountContextId;

    public void Start() => State = HostEngineState.Ready;

    public void Stop()
    {
        ClearAccount();
        State = HostEngineState.Stopped;
    }

    public string ActivateAccount(string profileId)
    {
        if (State != HostEngineState.Ready || !ValidProfileId(profileId))
        {
            throw new IptvValidationException("Perfil local inválido.");
        }
        if (!string.Equals(_activeProfileId, profileId, StringComparison.Ordinal))
        {
            ClearAccount();
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
                    media.Dispose();
                }
                catalog?.Dispose();
                throw;
            }
        }
        _accountContextId = Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
        return _accountContextId;
    }

    public void ClearAccount()
    {
        var media = _media;
        var catalog = _iptv;
        _accountContextId = null;
        _authorization = null;
        _media = null;
        _iptv = null;
        _activeProfileId = null;
        try
        {
            if (media is not null)
            {
                media.StateChanged -= OnMediaStateChanged;
                try
                {
                    media.StopAsync().GetAwaiter().GetResult();
                }
                finally
                {
                    media.Dispose();
                }
            }
        }
        finally
        {
            catalog?.Dispose();
        }
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

    public void ClearAuthorization()
    {
        if (_media?.Snapshot().Mode == "publish")
        {
            _media.StopAsync().GetAwaiter().GetResult();
        }
        _authorization = null;
    }

    public HostEngineSnapshot Snapshot() => HostEngineSnapshot.Create(
        State,
        _authorization,
        _accountContextId is not null,
        _media?.IsAvailable == true,
        _media?.Runtime.IsWhipAvailable == true);

    public IReadOnlyList<IptvSourceSummary> ListIptvSources() => Catalog().ListSources();
    public IptvSourceSummary AddIptvUrl(string name, string location) => Catalog().AddUrlSource(name, location);
    public IptvSourceSummary AddIptvFile(string path) => Catalog().AddFileSource(path);
    public bool RemoveIptvSource(long sourceId) => Catalog().RemoveSource(sourceId);
    public Task<IptvSourceSummary> RefreshIptvSourceAsync(long sourceId, CancellationToken cancellationToken) => Catalog().RefreshAsync(sourceId, cancellationToken);
    public IReadOnlyList<string> GetIptvGroups(long sourceId) => Catalog().GetGroups(sourceId);
    public IptvChannelSearchResult SearchIptvChannels(long sourceId, string? query, string? group, int offset, int limit) => Catalog().SearchChannels(sourceId, query, group, offset, limit);
    public Task<MediaSnapshot> StartIptvMediaAsync(long channelId, CancellationToken cancellationToken) => Media().StartAsync(channelId, cancellationToken);

    public Task<MediaSnapshot> StartIptvPublishAsync(long channelId, CancellationToken cancellationToken)
    {
        if (_authorization is null || _authorization.ExpiresAt <= DateTimeOffset.UtcNow)
        {
            throw new MediaEngineException("host_authorization_required");
        }
        return Media().StartPublishAsync(channelId, () => _authorization, cancellationToken);
    }

    public Task<MediaSnapshot> StopIptvMediaAsync() => Media().StopAsync();
    public MediaSnapshot IptvMediaSnapshot() => _media?.Snapshot() ?? MediaSnapshot.Idle;

    private IptvCatalogService Catalog() => State == HostEngineState.Ready && _iptv is not null
        ? _iptv : throw new IptvValidationException("O catálogo IPTV local não está disponível.");
    private MediaEngine Media() => State == HostEngineState.Ready && _media is not null
        ? _media : throw new MediaEngineException("media_runtime_unavailable");
    private void OnMediaStateChanged(object? sender, MediaSnapshot snapshot) => MediaStateChanged?.Invoke(this, snapshot);
    private static bool ValidProfileId(string value) => value.Length == 64 && value.All(character => character is >= 'a' and <= 'f' or >= '0' and <= '9');

    public void Dispose() => Stop();
}
