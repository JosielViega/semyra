using System.Diagnostics;
using System.Globalization;
using System.Text;
using Microsoft.Data.Sqlite;
using Semyra.Desktop.Host;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Tests;

public sealed class IptvFavoritesRecentsTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "semyra-iptv-preferences", Guid.NewGuid().ToString("N"));
    private readonly Protector _protector = new();

    [Fact]
    public void LogicalIdentityIsDeterministicAndIndependentFromStreamUrl()
    {
        var tvg = LogicalChannelIdentity.Create("  Rede  Um ", "Ignored", "Ignored");
        Assert.Equal(tvg, LogicalChannelIdentity.Create("rede um", "Outro", "Grupo"));
        Assert.NotEqual(tvg, LogicalChannelIdentity.Create("rede dois", "Ignored", "Ignored"));

        var fallback = LogicalChannelIdentity.Create(null, "  Café   News ", " Notícias ");
        Assert.Equal(fallback, LogicalChannelIdentity.Create("", "cafe\u0301 news", "notícias"));
        Assert.NotEqual(fallback, LogicalChannelIdentity.Create(null, "Café News", "Esportes"));
        Assert.Equal(
            LogicalChannelIdentity.Create("canal-1", "Canal", "Grupo"),
            LogicalChannelIdentity.Create("canal-1", "Canal", "Grupo"));
    }

    [Fact]
    public void InitializeMigratesVersionOneWithoutLosingCatalogOrProtectedLocation()
    {
        var path = DatabasePath();
        CreateVersionOneFixture(path);
        var store = new IptvStore(path, _protector);

        store.Initialize();

        Assert.Equal("https://provider.example/private.m3u", _protector.Unprotect(store.FindSource(1)!.ProtectedLocation));
        Assert.Single(store.SearchChannels(1, "", null, 0, 50).Channels);
        using var connection = Open(path);
        Assert.Equal(3L, ScalarLong(connection, "PRAGMA user_version"));
        Assert.Equal(1L, ScalarLong(connection, "SELECT COUNT(*) FROM iptv_channels WHERE logical_key IS NOT NULL AND logical_key <> ''"));
        Assert.Equal(1L, ScalarLong(connection, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='iptv_favorites'"));
        Assert.Equal(1L, ScalarLong(connection, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='iptv_recents'"));
    }

    [Fact]
    public void InitializeRecoversInterruptedVersionOneMigrationIdempotently()
    {
        var path = DatabasePath();
        CreateVersionOneFixture(path);
        using (var connection = Open(path))
        using (var command = connection.CreateCommand())
        {
            command.CommandText = """
                ALTER TABLE iptv_channels ADD COLUMN logical_key TEXT NULL;
                CREATE INDEX idx_iptv_channels_source_logical ON iptv_channels(source_id, logical_key);
                CREATE TABLE iptv_favorites (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, source_id INTEGER NOT NULL,
                    stable_key TEXT NOT NULL, logical_key TEXT NOT NULL, created_at TEXT NOT NULL,
                    FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                    UNIQUE (source_id, stable_key)
                );
                CREATE INDEX idx_iptv_favorites_source_logical ON iptv_favorites(source_id, logical_key);
                CREATE TABLE iptv_recents (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, source_id INTEGER NOT NULL,
                    stable_key TEXT NOT NULL, logical_key TEXT NOT NULL, last_watched_at TEXT NOT NULL,
                    FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                    UNIQUE (source_id, stable_key)
                );
                CREATE INDEX idx_iptv_recents_source_watched ON iptv_recents(source_id, last_watched_at DESC, id DESC);
                CREATE INDEX idx_iptv_recents_source_logical ON iptv_recents(source_id, logical_key);
                PRAGMA user_version = 1;
                """;
            command.ExecuteNonQuery();
        }

        var store = new IptvStore(path, _protector);
        store.Initialize();
        store.Initialize();

        Assert.Single(store.ListSources());
        Assert.Single(store.SearchChannels(1, "", null, 0, 50).Channels);
        using var verified = Open(path);
        Assert.Equal(3L, ScalarLong(verified, "PRAGMA user_version"));
        Assert.Equal(1L, ScalarLong(verified, "SELECT COUNT(*) FROM iptv_channels WHERE logical_key IS NOT NULL AND logical_key <> ''"));
        Assert.Equal(1L, ScalarLong(verified, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='iptv_favorites'"));
        Assert.Equal(1L, ScalarLong(verified, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='iptv_recents'"));
    }

    [Fact]
    public async Task FavoriteSurvivesRefreshWhenUrlChangesAndExactKeyIsRebound()
    {
        var store = Store();
        var source = store.AddSource("Fonte", IptvSourceType.M3uUrl, "https://provider.example/list.m3u");
        await Import(store, source.Id, Channel("Canal", "https://provider.example/old", "canal-1"));
        var old = Assert.Single(store.SearchChannels(source.Id, "", null, 0, 50).Channels);
        Assert.True(store.SetFavorite(old.Id, true));

        await Import(store, source.Id, Channel("Canal", "https://provider.example/new", "CANAL-1"));

        var favorite = Assert.Single(store.ListFavorites(source.Id, 0, 50).Channels);
        Assert.NotEqual(old.Id, favorite.Id);
        Assert.True(favorite.IsFavorite);
        Assert.True(Assert.Single(store.SearchChannels(source.Id, "", null, 0, 50).Channels).IsFavorite);
        Assert.False(store.SetFavorite(favorite.Id, false));
        Assert.Empty(store.ListFavorites(source.Id, 0, 50).Channels);
    }

    [Fact]
    public async Task AmbiguousLogicalFallbackNeverChoosesArbitrarilyAndCanReappearLater()
    {
        var store = Store();
        var source = store.AddSource("Fonte", IptvSourceType.M3uUrl, "https://provider.example/list.m3u");
        await Import(store, source.Id, Channel("Canal", "https://provider.example/old", "shared"));
        store.SetFavorite(Assert.Single(store.SearchChannels(source.Id, "", null, 0, 50).Channels).Id, true);

        await Import(store, source.Id,
            Channel("Canal A", "https://provider.example/a", "shared"),
            Channel("Canal B", "https://provider.example/b", "shared"));

        Assert.Empty(store.ListFavorites(source.Id, 0, 50).Channels);
        using (var connection = Open(DatabasePath()))
        {
            Assert.Equal(1L, ScalarLong(connection, "SELECT COUNT(*) FROM iptv_favorites"));
        }

        await Import(store, source.Id, Channel("Canal A", "https://provider.example/a2", "shared"));
        Assert.Single(store.ListFavorites(source.Id, 0, 50).Channels);
    }

    [Fact]
    public async Task RecentsAreUniqueOrderedAndLimitedPerSource()
    {
        var now = new DateTimeOffset(2026, 10, 9, 12, 0, 0, TimeSpan.Zero);
        var path = DatabasePath();
        var store = new IptvStore(path, _protector, () => now = now.AddSeconds(1));
        store.Initialize();
        var source = store.AddSource("Fonte", IptvSourceType.M3uUrl, "https://provider.example/list.m3u");
        await Import(store, source.Id, Enumerable.Range(1, 22)
            .Select(index => Channel("Canal " + index, "https://provider.example/" + index, "id-" + index)).ToArray());
        var channels = store.SearchChannels(source.Id, "", null, 0, 50).Channels;

        foreach (var channel in channels)
        {
            store.RecordRecent(channel.Id);
        }
        var retained = store.ListRecents(source.Id);
        Assert.Equal(20, retained.Count);
        Assert.Equal(channels[^1].Id, retained[0].ChannelId);

        var repeated = channels[5];
        store.RecordRecent(repeated.Id);
        retained = store.ListRecents(source.Id);
        Assert.Equal(repeated.Id, retained[0].ChannelId);
        Assert.Equal(1, retained.Count(channel => channel.ChannelId == repeated.Id));
    }

    [Fact]
    public async Task RecentSurvivesRefreshThroughUniqueLogicalIdentity()
    {
        var store = Store();
        var source = store.AddSource("Fonte", IptvSourceType.M3uUrl, "https://provider.example/list.m3u");
        await Import(store, source.Id, Channel("Canal", "https://provider.example/old", "recent-1"));
        var old = Assert.Single(store.SearchChannels(source.Id, "", null, 0, 50).Channels);
        store.RecordRecent(old.Id);

        await Import(store, source.Id, Channel("Canal", "https://provider.example/new", "RECENT-1"));

        var recent = Assert.Single(store.ListRecents(source.Id));
        Assert.NotEqual(old.Id, recent.ChannelId);
        Assert.Equal("Canal", recent.Name);
    }

    [Fact]
    public async Task RecentResolvedThroughLogicalIdentityCanBeRemovedAfterRefresh()
    {
        var store = Store();
        var source = store.AddSource("Fonte", IptvSourceType.M3uUrl, "https://provider.example/list.m3u");
        await Import(store, source.Id, Channel("Canal", "https://provider.example/old", "recent-logical"));
        store.RecordRecent(Assert.Single(store.SearchChannels(source.Id, "", null, 0, 50).Channels).Id);
        var recentId = Assert.Single(store.ListRecents(source.Id)).RecentId;
        await Import(store, source.Id, Channel("Canal", "https://provider.example/new", "RECENT-LOGICAL"));
        var refreshed = Assert.Single(store.ListRecents(source.Id));

        Assert.Equal(recentId, refreshed.RecentId);
        Assert.True(store.RemoveRecent(source.Id, refreshed.RecentId));
        Assert.Empty(store.ListRecents(source.Id));
    }

    [Fact]
    public async Task RecentCanBeRemovedWithoutChangingFavoriteOrOtherSource()
    {
        var store = Store();
        var first = store.AddSource("Primeira", IptvSourceType.M3uUrl, "https://provider.example/a.m3u");
        var second = store.AddSource("Segunda", IptvSourceType.M3uUrl, "https://provider.example/b.m3u");
        await Import(store, first.Id, Channel("Primeiro", "https://provider.example/a", "a"));
        await Import(store, second.Id, Channel("Segundo", "https://provider.example/b", "b"));
        var firstChannel = Assert.Single(store.SearchChannels(first.Id, "", null, 0, 50).Channels);
        var secondChannel = Assert.Single(store.SearchChannels(second.Id, "", null, 0, 50).Channels);
        Assert.True(store.SetFavorite(firstChannel.Id, true));
        store.RecordRecent(firstChannel.Id);
        store.RecordRecent(secondChannel.Id);

        var firstRecentId = Assert.Single(store.ListRecents(first.Id)).RecentId;
        Assert.True(store.RemoveRecent(first.Id, firstRecentId));
        Assert.False(store.RemoveRecent(first.Id, firstRecentId));
        Assert.False(store.RemoveRecent(first.Id, 999999));
        Assert.Empty(store.ListRecents(first.Id));
        Assert.Single(store.ListRecents(second.Id));
        Assert.Single(store.ListFavorites(first.Id, 0, 50).Channels);
        Assert.Single(store.SearchChannels(first.Id, "", null, 0, 50).Channels);
    }

    [Fact]
    public async Task ClearRecentsOnlyClearsRequestedSourceAndIsIdempotent()
    {
        var store = Store();
        var first = store.AddSource("Primeira", IptvSourceType.M3uUrl, "https://provider.example/a.m3u");
        var second = store.AddSource("Segunda", IptvSourceType.M3uUrl, "https://provider.example/b.m3u");
        await Import(store, first.Id, Enumerable.Range(1, 20)
            .Select(index => Channel("Primeiro " + index, "https://provider.example/a" + index, "a" + index)).ToArray());
        await Import(store, second.Id, Enumerable.Range(1, 20)
            .Select(index => Channel("Segundo " + index, "https://provider.example/b" + index, "b" + index)).ToArray());
        foreach (var channel in store.SearchChannels(first.Id, "", null, 0, 50).Channels) store.RecordRecent(channel.Id);
        foreach (var channel in store.SearchChannels(second.Id, "", null, 0, 50).Channels) store.RecordRecent(channel.Id);

        var stopwatch = Stopwatch.StartNew();
        Assert.Equal(20, store.ClearRecents(first.Id));
        stopwatch.Stop();
        Assert.Equal(0, store.ClearRecents(first.Id));
        Assert.Empty(store.ListRecents(first.Id));
        Assert.Equal(20, store.ListRecents(second.Id).Count);
        Assert.True(stopwatch.Elapsed < TimeSpan.FromSeconds(1));
        Assert.Equal(0, store.ClearRecents(999999));
    }

    [Fact]
    public async Task SeparateAccountDatabasesRestoreOnlyTheirOwnPreferences()
    {
        var profileA = new string('a', 64);
        var profileB = new string('b', 64);
        var storeA = new IptvStore(IptvPaths.DatabasePath(profileA, _root), _protector);
        var storeB = new IptvStore(IptvPaths.DatabasePath(profileB, _root), _protector);
        storeA.Initialize();
        storeB.Initialize();
        var sourceA = storeA.AddSource("Fonte", IptvSourceType.M3uUrl, "https://provider.example/a.m3u");
        var sourceB = storeB.AddSource("Fonte", IptvSourceType.M3uUrl, "https://provider.example/b.m3u");
        await Import(storeA, sourceA.Id, Channel("Canal", "https://provider.example/a", "same"));
        await Import(storeB, sourceB.Id, Channel("Canal", "https://provider.example/b", "same"));
        var channelA = Assert.Single(storeA.SearchChannels(sourceA.Id, "", null, 0, 50).Channels);
        storeA.SetFavorite(channelA.Id, true);
        storeA.RecordRecent(channelA.Id);

        Assert.Empty(storeB.ListFavorites(sourceB.Id, 0, 50).Channels);
        Assert.Empty(storeB.ListRecents(sourceB.Id));
        Assert.Single(storeA.ListFavorites(sourceA.Id, 0, 50).Channels);
        Assert.Single(storeA.ListRecents(sourceA.Id));
        var recentId = Assert.Single(storeA.ListRecents(sourceA.Id)).RecentId;
        Assert.True(storeA.RemoveRecent(sourceA.Id, recentId));
        Assert.Empty(storeA.ListRecents(sourceA.Id));
        Assert.Empty(storeB.ListRecents(sourceB.Id));
    }

    [Fact]
    public async Task PreferencesAreSourceScopedAndCascadeWhenSourceIsRemoved()
    {
        var store = Store();
        var first = store.AddSource("Primeira", IptvSourceType.M3uUrl, "https://provider.example/a.m3u");
        var second = store.AddSource("Segunda", IptvSourceType.M3uUrl, "https://provider.example/b.m3u");
        await Import(store, first.Id, Channel("Canal", "https://provider.example/a", "same"));
        await Import(store, second.Id, Channel("Canal", "https://provider.example/b", "same"));
        var firstChannel = Assert.Single(store.SearchChannels(first.Id, "", null, 0, 50).Channels);
        store.SetFavorite(firstChannel.Id, true);
        store.RecordRecent(firstChannel.Id);

        Assert.Single(store.ListFavorites(first.Id, 0, 50).Channels);
        Assert.Empty(store.ListFavorites(second.Id, 0, 50).Channels);
        Assert.Empty(store.ListRecents(second.Id));
        Assert.True(store.RemoveSource(first.Id));
        using var connection = Open(DatabasePath());
        Assert.Equal(0L, ScalarLong(connection, "SELECT COUNT(*) FROM iptv_favorites"));
        Assert.Equal(0L, ScalarLong(connection, "SELECT COUNT(*) FROM iptv_recents"));
        Assert.Equal(1L, ScalarLong(connection, "SELECT COUNT(*) FROM iptv_sources"));
    }

    private IptvStore Store()
    {
        var store = new IptvStore(DatabasePath(), _protector);
        store.Initialize();
        return store;
    }

    private string DatabasePath() => Path.Combine(_root, "semyra.db");

    private static ParsedM3uChannel Channel(string name, string url, string? tvgId = null, string? group = "Live") =>
        new(name, url, tvgId, null, group);

    private static async Task Import(IptvStore store, long sourceId, params ParsedM3uChannel[] channels) =>
        await store.ReplaceChannelsAsync(sourceId, AsAsync(channels), default);

    private static async IAsyncEnumerable<ParsedM3uChannel> AsAsync(IEnumerable<ParsedM3uChannel> channels)
    {
        foreach (var channel in channels)
        {
            yield return channel;
            await Task.Yield();
        }
    }

    private void CreateVersionOneFixture(string path)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        using var connection = Open(path);
        using var command = connection.CreateCommand();
        command.CommandText = """
            CREATE TABLE iptv_sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, type TEXT NOT NULL,
                protected_location BLOB NOT NULL, enabled INTEGER NOT NULL, refresh_status TEXT NOT NULL,
                refresh_error TEXT NULL, last_refresh_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            );
            CREATE TABLE iptv_channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT, source_id INTEGER NOT NULL, stable_key TEXT NOT NULL,
                external_id TEXT NULL, name TEXT NOT NULL, group_name TEXT NULL, logo_url TEXT NULL, tvg_id TEXT NULL,
                protected_stream_url BLOB NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                UNIQUE (source_id, stable_key)
            );
            INSERT INTO iptv_sources VALUES (1, 'Legada', 'm3u_url', $location, 1, 'ready', NULL, NULL, $now, $now);
            INSERT INTO iptv_channels VALUES (1, 1, 'old-stable', 'legacy-id', 'Canal legado', 'Live', NULL, 'legacy-id', $stream, $now, $now);
            PRAGMA user_version = 1;
            """;
        command.Parameters.Add("$location", SqliteType.Blob).Value = _protector.Protect("https://provider.example/private.m3u");
        command.Parameters.Add("$stream", SqliteType.Blob).Value = _protector.Protect("https://provider.example/private.ts");
        command.Parameters.AddWithValue("$now", DateTimeOffset.UtcNow.ToString("O", CultureInfo.InvariantCulture));
        command.ExecuteNonQuery();
    }

    private static SqliteConnection Open(string path)
    {
        var connection = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = path, Pooling = false }.ToString());
        connection.Open();
        using var foreignKeys = connection.CreateCommand();
        foreignKeys.CommandText = "PRAGMA foreign_keys = ON";
        foreignKeys.ExecuteNonQuery();
        return connection;
    }

    private static long ScalarLong(SqliteConnection connection, string sql)
    {
        using var command = connection.CreateCommand();
        command.CommandText = sql;
        return Convert.ToInt64(command.ExecuteScalar(), CultureInfo.InvariantCulture);
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, true);
    }

    private sealed class Protector : ISecretProtector
    {
        public byte[] Protect(string value) => Encoding.UTF8.GetBytes(Convert.ToBase64String(Encoding.UTF8.GetBytes(value)));
        public string Unprotect(byte[] value) => Encoding.UTF8.GetString(Convert.FromBase64String(Encoding.UTF8.GetString(value)));
    }

}
