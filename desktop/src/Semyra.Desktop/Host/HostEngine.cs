using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Host;

public sealed class HostEngine : IDisposable
{
    public HostEngineState State { get; private set; } = HostEngineState.Stopped;
    private HostAuthorization? _authorization;
    private readonly IptvCatalogService? _iptv;
    private readonly MediaEngine? _media;

    public HostEngine(IptvCatalogService? iptv = null, MediaEngine? media = null)
    {
        _iptv = iptv;
        _media = media;
    }

    public void Start()
    {
        _iptv?.Initialize();
        State = HostEngineState.Ready;
    }

    public void Stop()
    {
        _media?.StopAsync().GetAwaiter().GetResult();
        _authorization = null;
        State = HostEngineState.Stopped;
    }

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

    public HostEngineSnapshot Snapshot()
    {
        return HostEngineSnapshot.Create(State, _authorization, _media?.IsAvailable == true, _media?.Runtime.IsWhipAvailable == true);
    }

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

    private IptvCatalogService Catalog()
    {
        if (State != HostEngineState.Ready || _iptv is null)
        {
            throw new IptvValidationException("O catálogo IPTV local não está disponível.");
        }
        return _iptv;
    }

    private MediaEngine Media()
    {
        if (State != HostEngineState.Ready || _media is null)
        {
            throw new MediaEngineException("media_runtime_unavailable");
        }
        return _media;
    }

    public void Dispose()
    {
        Stop();
        _media?.Dispose();
        _iptv?.Dispose();
    }
}
