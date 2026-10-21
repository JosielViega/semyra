using System.Diagnostics;
using System.Net;
using System.Text;
using Microsoft.Data.Sqlite;
using Semyra.Desktop.Iptv;

namespace Semyra.Desktop.Tests;

public sealed class XtreamVodCatalogTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "semyra-vod-tests", Guid.NewGuid().ToString("N"));
    private readonly TestProtector _protector = new();

    [Fact]
    public async Task GlobalSyncSeparatesLiveMoviesSeriesAndLazyEpisodesWithoutPersistingUrls()
    {
        var database = DatabasePath(); var store = new IptvStore(database, _protector); store.Initialize();
        var handler = new VodHandler(); using var http = new HttpClient(handler); using var catalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), http);
        var source = await catalog.AddXtreamSourceAsync("Provider", "https://provider.example", "alice", "secret", default);
        Assert.Equal(2, source.ChannelCount); Assert.Equal(0, source.MovieCount); Assert.Equal(0, source.SeriesCount);

        source = await catalog.SyncVodAsync(source.Id, default);
        Assert.Equal(0, handler.SeriesInfoRequests);
        Assert.Equal("ready", source.VodSyncState); Assert.Equal(3, source.MovieCount); Assert.Equal(2, source.SeriesCount);
        Assert.Equal(2, catalog.GetVodCategories(source.Id, IptvVodMediaType.Movie).Count);
        var movie = Assert.Single(catalog.SearchMovies(source.Id, "Movie A", null, 0, 100).Items);
        var series = Assert.Single(catalog.SearchSeries(source.Id, "Series A", null, 0, 100).Items);
        Assert.Equal("https://provider.example/movie/alice/secret/200.mp4", catalog.ResolveMovieForPlayback(movie.Id).AbsoluteUri);

        var details = await catalog.GetSeriesDetailsAsync(series.Id, false, default);
        Assert.Equal(1, handler.SeriesInfoRequests);
        Assert.Equal(6, details.Episodes.Count); Assert.Equal(new[] { 0, 1, 1, 1, 2, 2 }, details.Episodes.Select(x => x.SeasonNumber));
        Assert.Equal("https://provider.example/series/alice/secret/301.mkv", catalog.ResolveEpisodeForPlayback(details.Episodes[1].Id).AbsoluteUri);

        var bytes = File.ReadAllBytes(database);
        Assert.False(Contains(bytes, "/movie/alice/secret/")); Assert.False(Contains(bytes, "/series/alice/secret/"));
        Assert.Equal(2, Scalar(database, "SELECT COUNT(*) FROM iptv_channels"));
        Assert.Equal(3, Scalar(database, "SELECT COUNT(*) FROM iptv_movies"));
        Assert.Equal(2, Scalar(database, "SELECT COUNT(*) FROM iptv_series"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_movies WHERE stable_key='xtream:movie:200'"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_series WHERE stable_key='xtream:series:500'"));
        Assert.Equal(6, Scalar(database, "SELECT COUNT(*) FROM iptv_series_episodes"));
    }

    [Fact]
    public async Task FailedGlobalSyncPreservesLastGoodCatalogAndUsesSafeError()
    {
        var store = new IptvStore(DatabasePath(), _protector); store.Initialize();
        using (var http = new HttpClient(new VodHandler())) using (var catalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), http))
        { var source = await catalog.AddXtreamSourceAsync("Provider", "https://provider.example", "alice", "secret", default); await catalog.SyncVodAsync(source.Id, default); }
        var id = Assert.Single(store.ListSources()).Id;
        using var failedHttp = new HttpClient(new SelectiveFailureHandler()); using var failed = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), failedHttp);
        await Assert.ThrowsAsync<IptvRefreshException>(() => failed.SyncVodAsync(id, default));
        var summary = Assert.Single(store.ListSources()); Assert.Equal("error", summary.VodSyncState); Assert.Equal("xtream_connect_failed", summary.VodSyncError); Assert.Equal(3, summary.MovieCount); Assert.Equal(2, summary.SeriesCount);
        Assert.Equal(3, store.SearchMovies(id, string.Empty, null, 0, 100).Items.Count);
    }

    [Fact]
    public async Task DuplicateMovieIdsCountOnlyUniquePersistedRows()
    {
        const string payload = "[{\"stream_id\":1,\"name\":\"One\"},{\"stream_id\":2,\"name\":\"Two\"},{\"stream_id\":2,\"name\":\"Two repeated\"},{\"stream_id\":3,\"name\":\"Three\"}]";
        using var parsed = System.Text.Json.JsonDocument.Parse(payload);
        Assert.Equal(4, parsed.RootElement.GetArrayLength());

        var store = new IptvStore(DatabasePath(), _protector); store.Initialize();
        var handler = new VodHandler { VodStreamsResponse = payload };
        using var http = new HttpClient(handler); using var catalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), http);
        var source = await catalog.AddXtreamSourceAsync("Provider", "https://provider.example", "alice", "secret", default);

        source = await catalog.SyncVodAsync(source.Id, default);

        Assert.Equal(3, source.MovieCount);
        Assert.Equal(3, Scalar(DatabasePath(), "SELECT COUNT(*) FROM iptv_movies"));
    }

    [Fact]
    public async Task SeriesRefreshReplacesEpisodesAndFailurePreservesLastGoodCache()
    {
        var store = new IptvStore(DatabasePath(), _protector); store.Initialize();
        var handler = new VodHandler(
            "{\"episodes\":{\"1\":[{\"id\":301,\"episode_num\":1,\"title\":\"One\"},{\"id\":302,\"episode_num\":2,\"title\":\"Two\"}]}}",
            "{\"episodes\":{\"1\":[{\"id\":301,\"episode_num\":1,\"title\":\"One\"},{\"id\":302,\"episode_num\":2,\"title\":\"Two\"},{\"id\":303,\"episode_num\":3,\"title\":\"Three\"}]}}",
            VodHandler.Failure);
        using var http = new HttpClient(handler); using var catalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), http);
        var source = await catalog.AddXtreamSourceAsync("Provider", "https://provider.example", "alice", "secret", default);
        await catalog.SyncVodAsync(source.Id, default);
        var series = Assert.Single(catalog.SearchSeries(source.Id, "Series A", null, 0, 100).Items);

        Assert.Equal(2, (await catalog.GetSeriesDetailsAsync(series.Id, false, default)).Episodes.Count);
        Assert.Equal(3, (await catalog.GetSeriesDetailsAsync(series.Id, true, default)).Episodes.Count);
        var stale = await catalog.GetSeriesDetailsAsync(series.Id, true, default);
        Assert.True(stale.Stale);
        Assert.Equal(3, stale.Episodes.Count);
        Assert.Equal(3, store.GetSeriesDetails(series.Id).Episodes.Count);
        Assert.Equal(3, handler.SeriesInfoRequests);
    }

    [Fact]
    public async Task SourceRemovalCascadesVodAndCancelsAnActiveSyncWithoutRecreatingState()
    {
        var database = DatabasePath(); var store = new IptvStore(database, _protector); store.Initialize();
        var handler = new BlockingVodHandler(); using var http = new HttpClient(handler); using var catalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), http);
        var source = store.AddSource("Provider", IptvSourceType.Xtream, "{\"version\":1,\"baseUrl\":\"https://provider.example\",\"username\":\"alice\",\"password\":\"secret\"}");

        var sync = catalog.SyncVodAsync(source.Id, default);
        await handler.Started.Task.WaitAsync(TimeSpan.FromSeconds(5));
        await Assert.ThrowsAsync<IptvValidationException>(() => catalog.SyncVodAsync(source.Id, default));
        Assert.True(catalog.RemoveSource(source.Id));
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => sync);
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_vod_sync"));
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_vod_categories"));
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_movies"));
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_series"));
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_series_episodes"));
    }

    [Fact]
    public void VersionThreeMigratesThroughFiveWithoutRebuildingLiveTables()
    {
        var database = DatabasePath(); using (var c = Open(database)) using (var command = c.CreateCommand()) { command.CommandText = """
            CREATE TABLE iptv_sources(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,type TEXT NOT NULL CHECK(type IN('m3u_url','m3u_file','xtream')),protected_location BLOB NOT NULL,enabled INTEGER NOT NULL,refresh_status TEXT NOT NULL,refresh_error TEXT NULL,last_refresh_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
            CREATE TABLE iptv_channels(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id INTEGER NOT NULL,stable_key TEXT NOT NULL,logical_key TEXT NOT NULL,external_id TEXT NULL,name TEXT NOT NULL,group_name TEXT NULL,logo_url TEXT NULL,tvg_id TEXT NULL,protected_stream_url BLOB NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,UNIQUE(source_id,stable_key));
            CREATE TABLE iptv_favorites(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id INTEGER NOT NULL,stable_key TEXT NOT NULL,logical_key TEXT NOT NULL,created_at TEXT NOT NULL,FOREIGN KEY(source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,UNIQUE(source_id,stable_key));
            CREATE TABLE iptv_recents(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id INTEGER NOT NULL,stable_key TEXT NOT NULL,logical_key TEXT NOT NULL,last_watched_at TEXT NOT NULL,FOREIGN KEY(source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,UNIQUE(source_id,stable_key));
            INSERT INTO iptv_sources VALUES(1,'Live','m3u_url',X'01',1,'ready',NULL,NULL,'now','now');
            INSERT INTO iptv_channels VALUES(1,1,'stable','logical',NULL,'Channel',NULL,NULL,NULL,X'02','now','now');
            PRAGMA user_version=3;
            """; command.ExecuteNonQuery(); }
        new IptvStore(database, _protector).Initialize();
        Assert.Equal(6, Scalar(database, "PRAGMA user_version")); Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_channels")); Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM pragma_table_info('iptv_movies') WHERE name='external_stream_id'"));
    }

    [Fact]
    public void BulkReplaceHandlesFiftyThousandMoviesAndTenThousandSeriesWithOneAtomicStoreOperation()
    {
        var store = new IptvStore(DatabasePath(), _protector); store.Initialize(); var source = store.AddSource("Scale", IptvSourceType.Xtream, "protected");
        var movies = Enumerable.Range(1, 50_000).Select(i => new XtreamMovie(i.ToString(), $"Movie {i}", "1", null, "mp4", null, null, null, null, null, null)).ToArray();
        var series = Enumerable.Range(1, 10_000).Select(i => new XtreamSeries(i.ToString(), $"Series {i}", "2", null, null, null, null, null, null, null)).ToArray();
        var watch = Stopwatch.StartNew(); var result = store.ReplaceVodCatalog(source.Id, new XtreamVodImport(new[] { new XtreamVodCategory("1", "Movies", IptvVodMediaType.Movie, 0), new XtreamVodCategory("2", "Series", IptvVodMediaType.Series, 0) }, movies, series)); watch.Stop();
        Assert.Equal(50_000, result.MovieCount); Assert.Equal(10_000, result.SeriesCount); Assert.True(watch.Elapsed < TimeSpan.FromSeconds(30), $"Bulk import took {watch.Elapsed}.");
        Assert.Equal(100, store.SearchMovies(source.Id, "Movie", null, 0, 100).Items.Count);
        Assert.Equal(50, store.SearchMovies(source.Id, "Movie", "1", 0, 50).Items.Count);
        Assert.True(store.SearchMovies(source.Id, "Movie", "1", 0, 50).HasMore);
        Assert.Equal(50, store.SearchSeries(source.Id, "Series", "2", 50, 50).Items.Count);
        Assert.Empty(store.SearchMovies(source.Id, "missing", null, 0, 50).Items);

        var pagedSource = store.AddSource("Paged", IptvSourceType.Xtream, "protected");
        store.ReplaceVodCatalog(pagedSource.Id, new XtreamVodImport(
            [new XtreamVodCategory("1", "Movies", IptvVodMediaType.Movie, 0), new XtreamVodCategory("2", "Series", IptvVodMediaType.Series, 0)],
            movies.Take(120).ToArray(),
            series.Take(120).ToArray()));
        Assert.Equal([50, 50, 20], new[] { 0, 50, 100 }.Select(offset => store.SearchMovies(pagedSource.Id, "Movie", "1", offset, 50).Items.Count));
        Assert.Equal([50, 50, 20], new[] { 0, 50, 100 }.Select(offset => store.SearchSeries(pagedSource.Id, "Series", "2", offset, 50).Items.Count));
    }

    public void Dispose() { if (Directory.Exists(_directory)) Directory.Delete(_directory, true); }
    private string DatabasePath() { Directory.CreateDirectory(_directory); return Path.Combine(_directory, "semyra.db"); }
    private static SqliteConnection Open(string path) { var c = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = path, Pooling = false }.ToString()); c.Open(); return c; }
    private static long Scalar(string path, string sql) { using var c = Open(path); using var cmd = c.CreateCommand(); cmd.CommandText = sql; return Convert.ToInt64(cmd.ExecuteScalar()); }
    private static bool Contains(byte[] bytes, string text) => bytes.AsSpan().IndexOf(Encoding.UTF8.GetBytes(text)) >= 0;
    private sealed class TestProtector : ISecretProtector { public byte[] Protect(string value) => Encoding.UTF8.GetBytes(Convert.ToBase64String(Encoding.UTF8.GetBytes(value))); public string Unprotect(byte[] value) => Encoding.UTF8.GetString(Convert.FromBase64String(Encoding.UTF8.GetString(value))); }
    private static HttpResponseMessage Json(string body) => new(HttpStatusCode.OK) { Content = new StringContent(body, Encoding.UTF8, "application/json") };
    private sealed class VodHandler(params string[] seriesResponses) : HttpMessageHandler
    {
        public const string Failure = "__failure__";
        private readonly Queue<string> _seriesResponses = new(seriesResponses.Length == 0 ? new[] { "{\"episodes\":{\"0\":[{\"id\":300,\"episode_num\":1,\"title\":\"Pilot\"}],\"1\":[{\"id\":301,\"episode_num\":1,\"title\":\"One\",\"container_extension\":\"mkv\"},{\"id\":302,\"episode_num\":2,\"title\":\"Two\"},{\"id\":303,\"episode_num\":3,\"title\":\"Three\"}],\"2\":[{\"id\":304,\"episode_num\":1,\"title\":\"Four\"},{\"id\":305,\"episode_num\":2,\"title\":\"Five\"}]}}" } : seriesResponses);
        public string? VodStreamsResponse { get; init; }
        public int SeriesInfoRequests { get; private set; }
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken token)
        {
            var q = request.RequestUri!.Query;
            if (q.Contains("get_series_info"))
            {
                SeriesInfoRequests++;
                var response = _seriesResponses.Dequeue();
                return Task.FromResult(response == Failure ? new HttpResponseMessage(HttpStatusCode.InternalServerError) : Json(response));
            }
            var body = q.Contains("get_live_categories") ? "[{\"category_id\":\"7\",\"category_name\":\"Live\"}]" : q.Contains("get_live_streams") ? "[{\"stream_id\":100,\"name\":\"Live A\"},{\"stream_id\":101,\"name\":\"Live B\"}]" : q.Contains("get_vod_categories") ? "[{\"category_id\":\"1\",\"category_name\":\"Movies\"},{\"category_id\":\"3\",\"category_name\":\"Kids\"}]" : q.Contains("get_vod_streams") ? VodStreamsResponse ?? "[{\"stream_id\":200,\"name\":\"Movie A\",\"category_id\":1,\"container_extension\":\"mp4\"},{\"stream_id\":201,\"name\":\"Movie B\",\"category_id\":3},{\"stream_id\":202,\"name\":\"Movie C\",\"category_id\":1}]" : q.Contains("get_series_categories") ? "[{\"category_id\":\"2\",\"category_name\":\"Series\"}]" : q.Contains("action=get_series") ? "[{\"series_id\":500,\"name\":\"Series A\",\"category_id\":2},{\"series_id\":501,\"name\":\"Series B\",\"category_id\":2}]" : "{\"user_info\":{\"auth\":1}}";
            return Task.FromResult(Json(body));
        }
    }
    private sealed class BlockingVodHandler : HttpMessageHandler
    {
        public TaskCompletionSource Started { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken token)
        {
            if (!request.RequestUri!.Query.Contains("get_vod_categories")) return Json("{\"user_info\":{\"auth\":1}}");
            Started.TrySetResult(); await Task.Delay(Timeout.InfiniteTimeSpan, token); return Json("[]");
        }
    }
    private sealed class SelectiveFailureHandler : HttpMessageHandler
    { protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken token) => Task.FromResult(request.RequestUri!.Query.Contains("get_vod_streams") ? new HttpResponseMessage(HttpStatusCode.InternalServerError) : Json(request.RequestUri.Query.Contains("get_vod_categories") ? "[]" : "{\"user_info\":{\"auth\":1}}")); }
}
