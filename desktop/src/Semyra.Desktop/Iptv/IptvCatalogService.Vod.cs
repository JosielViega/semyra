using System.IO;
using System.Security.Cryptography;

namespace Semyra.Desktop.Iptv;

public sealed partial class IptvCatalogService
{
    private readonly object _vodGate = new();
    private readonly Dictionary<long, CancellationTokenSource> _vodOperations = new();
    private readonly HashSet<(long SourceId, long SeriesId)> _seriesOperations = new();
    private readonly Dictionary<long, CancellationTokenSource> _sourceCancellation = new();

    public async Task<IptvSourceSummary> SyncVodAsync(long sourceId, CancellationToken cancellationToken)
    {
        sourceId = PositiveId(sourceId); CancellationTokenSource owned;
        lock (_vodGate)
        {
            if (_vodOperations.ContainsKey(sourceId) || _seriesOperations.Any(item => item.SourceId == sourceId)) throw new IptvValidationException("vod_sync_in_progress");
            owned = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken, SourceToken(sourceId)); _vodOperations.Add(sourceId, owned);
        }
        _store.MarkVodSyncing(sourceId);
        try
        {
            var configuration = GetXtreamConfiguration(sourceId);
            await _xtreamClient.AuthenticateAsync(configuration, owned.Token).ConfigureAwait(false);
            var movieCategories = await _xtreamClient.GetVodCategoriesAsync(configuration, owned.Token).ConfigureAwait(false);
            var movies = await _xtreamClient.GetVodStreamsAsync(configuration, owned.Token).ConfigureAwait(false);
            var seriesCategories = await _xtreamClient.GetSeriesCategoriesAsync(configuration, owned.Token).ConfigureAwait(false);
            var series = await _xtreamClient.GetSeriesAsync(configuration, owned.Token).ConfigureAwait(false);
            return _store.ReplaceVodCatalog(sourceId, new XtreamVodImport(movieCategories.Concat(seriesCategories).ToArray(), movies, series));
        }
        catch (OperationCanceledException) { _store.MarkVodSyncError(sourceId, "vod_sync_cancelled"); throw; }
        catch (XtreamException exception) { _store.MarkVodSyncError(sourceId, exception.Code); throw new IptvRefreshException(exception.Code, exception); }
        catch (Exception exception) when (exception is CryptographicException or InvalidDataException) { _store.MarkVodSyncError(sourceId, "vod_sync_failed"); throw new IptvRefreshException("vod_sync_failed", exception); }
        finally { lock (_vodGate) { _vodOperations.Remove(sourceId); owned.Dispose(); } }
    }

    public IReadOnlyList<IptvVodCategorySummary> GetVodCategories(long sourceId, IptvVodMediaType type) => _store.GetVodCategories(PositiveId(sourceId), type);

    public IptvVodSearchResult<IptvMovieSummary> SearchMovies(long sourceId, string? query, string? category, int offset, int limit)
    { ValidateVodSearch(query, category, offset, limit); return _store.SearchMovies(PositiveId(sourceId), (query ?? string.Empty).Trim(), Clean(category), offset, limit); }

    public IptvVodSearchResult<IptvSeriesSummary> SearchSeries(long sourceId, string? query, string? category, int offset, int limit)
    { ValidateVodSearch(query, category, offset, limit); return _store.SearchSeries(PositiveId(sourceId), (query ?? string.Empty).Trim(), Clean(category), offset, limit); }

    public async Task<IptvSeriesDetails> GetSeriesDetailsAsync(long seriesId, bool refresh, CancellationToken cancellationToken)
    {
        seriesId = PositiveId(seriesId); var identity = _store.FindSeriesIdentity(seriesId); var key = (identity.SourceId, seriesId);
        if (!refresh) { var cached = _store.GetSeriesDetails(seriesId); if (cached.LastSyncedAt is not null) return cached; }
        lock (_vodGate) { if (_vodOperations.ContainsKey(identity.SourceId) || !_seriesOperations.Add(key)) throw new IptvValidationException("series_sync_in_progress"); }
        try
        {
            CancellationToken sourceToken;
            lock (_vodGate) { sourceToken = SourceToken(identity.SourceId); }
            using var linked = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken, sourceToken);
            var config = GetXtreamConfiguration(identity.SourceId);
            var episodes = await _xtreamClient.GetSeriesInfoAsync(config, identity.ExternalSeriesId, linked.Token).ConfigureAwait(false);
            return _store.ReplaceSeriesEpisodes(seriesId, episodes);
        }
        catch (XtreamException exception) { throw new IptvRefreshException(exception.Code, exception); }
        finally { lock (_vodGate) { _seriesOperations.Remove(key); } }
    }

    internal Uri ResolveMovieForPlayback(long movieId) => ResolveVod(_store.ResolveMovieForPlayback(PositiveId(movieId)));
    internal Uri ResolveEpisodeForPlayback(long episodeId) => ResolveVod(_store.ResolveEpisodeForPlayback(PositiveId(episodeId)));

    private Uri ResolveVod(IptvVodPlaybackItem item)
    { var config = GetXtreamConfiguration(item.SourceId); return new Uri(XtreamClient.BuildVodUrl(config, item.Kind, item.ExternalId, item.ContainerExtension)); }

    private XtreamSourceConfiguration GetXtreamConfiguration(long sourceId)
    {
        var source = _store.FindSource(sourceId) ?? throw new IptvValidationException("Fonte não encontrada.");
        if (source.Type != IptvSourceType.Xtream) throw new IptvValidationException("vod_requires_xtream");
        return DeserializeXtreamConfiguration(_protector.Unprotect(source.ProtectedLocation));
    }

    private static void ValidateVodSearch(string? query, string? category, int offset, int limit)
    { if (offset < 0 || limit is < 1 or > 100 || (query?.Trim().Length ?? 0) > 120 || (category?.Trim().Length ?? 0) > 128) throw new IptvValidationException("Paginação ou filtro inválido."); }
    private static string? Clean(string? value) => string.IsNullOrWhiteSpace(value) ? null : value.Trim();
    private CancellationToken SourceToken(long sourceId)
    {
        if (!_sourceCancellation.TryGetValue(sourceId, out var cancellation))
        {
            cancellation = new CancellationTokenSource(); _sourceCancellation.Add(sourceId, cancellation);
        }
        return cancellation.Token;
    }
}
