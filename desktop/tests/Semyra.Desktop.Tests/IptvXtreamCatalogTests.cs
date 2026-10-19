using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using Microsoft.Data.Sqlite;
using Semyra.Desktop.Iptv;

namespace Semyra.Desktop.Tests;

public sealed class IptvXtreamCatalogTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "semyra-xtream-tests", Guid.NewGuid().ToString("N"));
    private readonly CapturingProtector _protector = new();

    [Theory]
    [InlineData("file:///private/provider")]
    [InlineData("ftp://provider.example")]
    [InlineData("https://user:pass@provider.example")]
    [InlineData("https://provider.example/player_api.php")]
    [InlineData("https://provider.example?token=secret")]
    [InlineData("https://provider.example#fragment")]
    public async Task XtreamBaseUrlRejectsNonServerLocations(string baseUrl)
    {
        var store = new IptvStore(DatabasePath(), _protector);
        store.Initialize();
        using var catalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), new HttpClient(new StatusHandler(HttpStatusCode.OK)));
        await Assert.ThrowsAsync<IptvValidationException>(() =>
            catalog.AddXtreamSourceAsync("Provider", baseUrl, "user", "pass", default));
        Assert.Empty(catalog.ListSources());
    }

    [Fact]
    public async Task AddSyncPlaybackRefreshAndPreferencesUseStableStreamIdentity()
    {
        var database = DatabasePath();
        var store = new IptvStore(database, _protector);
        store.Initialize();
        using var http = new HttpClient(new XtreamFixtureHandler());
        using var catalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), http);

        var source = await catalog.AddXtreamSourceAsync("Provider", "https://provider.example:8443/", "alice", "secret", default);
        Assert.Equal("xtream", source.Type);
        Assert.Equal("ready", source.LastRefreshStatus);
        Assert.Equal(2, source.ChannelCount);
        Assert.Equal(new[] { "News" }, catalog.GetGroups(source.Id));

        var channel = Assert.Single(catalog.SearchChannels(source.Id, "Channel A", "News", 0, 50).Channels);
        catalog.SetFavorite(channel.Id, true);
        store.RecordRecent(channel.Id);
        Assert.Equal("https://provider.example:8443/live/alice/secret/100.ts", store.ResolveChannelForPlayback(channel.Id).StreamUri.AbsoluteUri);

        var updated = new XtreamSourceConfiguration(1, "http://backup.example:8080", "new user", "new/pass");
        UpdateProtectedConfiguration(database, source.Id, JsonSerializer.Serialize(updated, new JsonSerializerOptions(JsonSerializerDefaults.Web)));
        await catalog.RefreshAsync(source.Id, default);

        var refreshed = Assert.Single(catalog.SearchChannels(source.Id, "Updated", "News", 0, 50).Channels);
        Assert.True(refreshed.IsFavorite);
        Assert.Single(catalog.ListFavorites(source.Id, 0, 50).Channels);
        Assert.Single(catalog.ListRecents(source.Id));
        Assert.Equal("http://backup.example:8080/live/new%20user/new%2Fpass/100.ts", store.ResolveChannelForPlayback(refreshed.Id).StreamUri.AbsoluteUri);

        var databaseBytes = File.ReadAllBytes(database);
        Assert.False(Contains(databaseBytes, Encoding.UTF8.GetBytes("alice")));
        Assert.False(Contains(databaseBytes, Encoding.UTF8.GetBytes("secret")));
        Assert.False(Contains(databaseBytes, Encoding.UTF8.GetBytes("/live/alice/secret/")));

        Assert.True(catalog.RemoveSource(source.Id));
        Assert.Empty(catalog.ListSources());
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_channels"));
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_favorites"));
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_recents"));
    }

    [Fact]
    public void VersionTwoMigratesToFourWithoutLosingM3uData()
    {
        var database = DatabasePath();
        using (var connection = Open(database))
        using (var command = connection.CreateCommand())
        {
            command.CommandText = """
                CREATE TABLE iptv_sources (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
                    type TEXT NOT NULL CHECK (type IN ('m3u_url', 'm3u_file')),
                    protected_location BLOB NOT NULL, enabled INTEGER NOT NULL,
                    refresh_status TEXT NOT NULL, refresh_error TEXT NULL,
                    last_refresh_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
                CREATE TABLE iptv_channels (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, source_id INTEGER NOT NULL,
                    stable_key TEXT NOT NULL, logical_key TEXT NOT NULL, external_id TEXT NULL,
                    name TEXT NOT NULL, group_name TEXT NULL, logo_url TEXT NULL, tvg_id TEXT NULL,
                    protected_stream_url BLOB NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                    FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                    UNIQUE(source_id, stable_key));
                CREATE TABLE iptv_favorites (id INTEGER PRIMARY KEY AUTOINCREMENT, source_id INTEGER NOT NULL, stable_key TEXT NOT NULL, logical_key TEXT NOT NULL, created_at TEXT NOT NULL, FOREIGN KEY(source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE, UNIQUE(source_id, stable_key));
                CREATE TABLE iptv_recents (id INTEGER PRIMARY KEY AUTOINCREMENT, source_id INTEGER NOT NULL, stable_key TEXT NOT NULL, logical_key TEXT NOT NULL, last_watched_at TEXT NOT NULL, FOREIGN KEY(source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE, UNIQUE(source_id, stable_key));
                INSERT INTO iptv_sources VALUES (1, 'M3U', 'm3u_url', X'01', 1, 'ready', NULL, NULL, 'now', 'now');
                INSERT INTO iptv_channels VALUES (1, 1, 'stable', 'logical', NULL, 'Channel', NULL, NULL, NULL, X'02', 'now', 'now');
                INSERT INTO iptv_favorites VALUES (1, 1, 'stable', 'logical', 'now');
                INSERT INTO iptv_recents VALUES (1, 1, 'stable', 'logical', 'now');
                PRAGMA user_version = 2;
                """;
            command.ExecuteNonQuery();
        }

        var store = new IptvStore(database, _protector);
        store.Initialize();

        Assert.Equal(4, Scalar(database, "PRAGMA user_version"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_sources WHERE type = 'm3u_url'"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_channels"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_favorites"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_recents"));
        var xtream = store.AddSource("Xtream", IptvSourceType.Xtream, "{}");
        Assert.Equal("xtream", xtream.Type);
    }

    [Fact]
    public async Task SameStreamIdFromDifferentSourcesNeverCollidesAndFailedRefreshKeepsCatalog()
    {
        var store = new IptvStore(DatabasePath(), _protector);
        store.Initialize();
        using var goodHttp = new HttpClient(new XtreamFixtureHandler());
        using var catalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), goodHttp);
        var first = await catalog.AddXtreamSourceAsync("A", "https://a.example", "user", "pass", default);
        var second = await catalog.AddXtreamSourceAsync("B", "https://b.example", "user", "pass", default);
        var firstChannel = Assert.Single(catalog.SearchChannels(first.Id, "Channel A", null, 0, 50).Channels);
        var secondChannel = Assert.Single(catalog.SearchChannels(second.Id, "Channel A", null, 0, 50).Channels);
        Assert.NotEqual(firstChannel.Id, secondChannel.Id);

        using var failingHttp = new HttpClient(new StatusHandler(HttpStatusCode.InternalServerError));
        using var failingCatalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient(), failingHttp);
        var error = await Assert.ThrowsAsync<IptvRefreshException>(() => failingCatalog.RefreshAsync(first.Id, default));
        Assert.Equal("xtream_connect_failed", error.Message);
        Assert.Equal(2, catalog.SearchChannels(first.Id, string.Empty, null, 0, 50).Channels.Count);
    }

    public void Dispose()
    {
        if (Directory.Exists(_directory)) Directory.Delete(_directory, true);
    }

    private string DatabasePath()
    {
        Directory.CreateDirectory(_directory);
        return Path.Combine(_directory, "semyra.db");
    }

    private void UpdateProtectedConfiguration(string database, long sourceId, string value)
    {
        using var connection = Open(database);
        using var command = connection.CreateCommand();
        command.CommandText = "UPDATE iptv_sources SET protected_location = $value WHERE id = $id";
        command.Parameters.Add("$value", SqliteType.Blob).Value = _protector.Protect(value);
        command.Parameters.AddWithValue("$id", sourceId);
        Assert.Equal(1, command.ExecuteNonQuery());
    }

    private static SqliteConnection Open(string database)
    {
        var connection = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = database, Pooling = false }.ToString());
        connection.Open();
        return connection;
    }

    private static long Scalar(string database, string sql)
    {
        using var connection = Open(database);
        using var command = connection.CreateCommand();
        command.CommandText = sql;
        return Convert.ToInt64(command.ExecuteScalar());
    }

    private static bool Contains(byte[] haystack, byte[] needle) => haystack.AsSpan().IndexOf(needle) >= 0;

    private sealed class CapturingProtector : ISecretProtector
    {
        public byte[] Protect(string value) => Encoding.UTF8.GetBytes(Convert.ToBase64String(Encoding.UTF8.GetBytes(value)));
        public string Unprotect(byte[] value) => Encoding.UTF8.GetString(Convert.FromBase64String(Encoding.UTF8.GetString(value)));
    }

    private sealed class XtreamFixtureHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var query = request.RequestUri!.Query;
            var action = query.Contains("action=get_live_categories", StringComparison.Ordinal) ? "get_live_categories"
                : query.Contains("action=get_live_streams", StringComparison.Ordinal) ? "get_live_streams"
                : null;
            var body = action switch
            {
                null => "{\"user_info\":{\"auth\":1}}",
                "get_live_categories" => "[{\"category_id\":\"7\",\"category_name\":\"News\"}]",
                "get_live_streams" => "[{\"stream_id\":\"100\",\"name\":\"Channel A Updated\",\"category_id\":\"7\",\"epg_channel_id\":\"epg-a\"},{\"stream_id\":101,\"name\":\"Channel B\"},{\"stream_id\":\"100\",\"name\":\"Duplicate\"}]",
                _ => "{}",
            };
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(body, Encoding.UTF8, "application/json"),
            });
        }
    }

    private sealed class StatusHandler(HttpStatusCode status) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            Task.FromResult(new HttpResponseMessage(status));
    }
}
