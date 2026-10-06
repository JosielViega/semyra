using Semyra.Desktop.Iptv;

namespace Semyra.Desktop.Host;

public sealed class HostEngine
{
    public HostEngineState State { get; private set; } = HostEngineState.Stopped;
    private HostAuthorization? _authorization;
    private readonly IptvCatalogService? _iptv;

    public HostEngine(IptvCatalogService? iptv = null)
    {
        _iptv = iptv;
    }

    public void Start()
    {
        _iptv?.Initialize();
        State = HostEngineState.Ready;
    }

    public void Stop()
    {
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
        _authorization = null;
    }

    public HostEngineSnapshot Snapshot()
    {
        return HostEngineSnapshot.Create(State, _authorization);
    }

    public IReadOnlyList<IptvSourceSummary> ListIptvSources() => Catalog().ListSources();
    public IptvSourceSummary AddIptvUrl(string name, string location) => Catalog().AddUrlSource(name, location);
    public IptvSourceSummary AddIptvFile(string path) => Catalog().AddFileSource(path);
    public bool RemoveIptvSource(long sourceId) => Catalog().RemoveSource(sourceId);
    public Task<IptvSourceSummary> RefreshIptvSourceAsync(long sourceId, CancellationToken cancellationToken) => Catalog().RefreshAsync(sourceId, cancellationToken);
    public IReadOnlyList<string> GetIptvGroups(long sourceId) => Catalog().GetGroups(sourceId);
    public IptvChannelSearchResult SearchIptvChannels(long sourceId, string? query, string? group, int offset, int limit) => Catalog().SearchChannels(sourceId, query, group, offset, limit);

    private IptvCatalogService Catalog()
    {
        if (State != HostEngineState.Ready || _iptv is null)
        {
            throw new IptvValidationException("O catálogo IPTV local não está disponível.");
        }
        return _iptv;
    }
}
