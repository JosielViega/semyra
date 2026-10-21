using System.Text;
using Microsoft.Data.Sqlite;
using Semyra.Desktop.Iptv;

namespace Semyra.Desktop.Tests;

public sealed class IptvVodProgressTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "semyra-vod-progress", Guid.NewGuid().ToString("N"));
    private readonly TestProtector _protector = new();

    [Fact]
    public void VersionFourMigratesEpisodesToStableKeysAndCreatesProgressSchema()
    {
        var database = DatabasePath();
        using (var connection = Open(database))
        using (var command = connection.CreateCommand())
        {
            command.CommandText = """
                CREATE TABLE iptv_sources(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,type TEXT NOT NULL,protected_location BLOB NOT NULL,enabled INTEGER NOT NULL,refresh_status TEXT NOT NULL,refresh_error TEXT NULL,last_refresh_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
                CREATE TABLE iptv_series(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id INTEGER NOT NULL,external_series_id TEXT NOT NULL,stable_key TEXT NOT NULL,external_category_id TEXT NULL,name TEXT NOT NULL,poster_url TEXT NULL,year TEXT NULL,rating TEXT NULL,plot TEXT NULL,genre TEXT NULL,release_date TEXT NULL,added TEXT NULL,episodes_synced_at TEXT NULL);
                CREATE TABLE iptv_series_episodes(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id INTEGER NOT NULL,series_id INTEGER NOT NULL,external_episode_id TEXT NOT NULL,season_number INTEGER NOT NULL,episode_number INTEGER NULL,name TEXT NOT NULL,container_extension TEXT NULL,duration TEXT NULL,plot TEXT NULL,added TEXT NULL);
                INSERT INTO iptv_sources VALUES(1,'Provider','xtream',X'01',1,'ready',NULL,NULL,'now','now');
                INSERT INTO iptv_series VALUES(4,1,'500','xtream:series:500',NULL,'Series',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL);
                INSERT INTO iptv_series_episodes VALUES(7,1,4,'301',1,2,'Episode','mkv',NULL,NULL,NULL);
                PRAGMA user_version=4;
                """;
            command.ExecuteNonQuery();
        }

        new IptvStore(database, _protector).Initialize();

        Assert.Equal(6, Scalar(database, "PRAGMA user_version"));
        Assert.Equal("xtream:episode:500:301", Text(database, "SELECT stable_key FROM iptv_series_episodes WHERE id=7"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM pragma_table_info('iptv_vod_progress') WHERE name='last_watched_at'"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='iptv_vod_continue_dismissals'"));
    }

    [Fact]
    public async Task VersionFiveToSixPreservesLiveVodEpisodesAndProgress()
    {
        var database = DatabasePath();
        var store = NewStore(database);
        var source = AddCatalog(store);
        await store.ReplaceChannelsAsync(source.Id, LiveChannels(), default);
        var series = Assert.Single(store.SearchSeries(source.Id, "Series", null, 0, 10).Items);
        var episodes = store.ReplaceSeriesEpisodes(series.Id, Episodes()).Episodes;
        store.SaveVodProgress(store.ResolveMovieForPlayback(Assert.Single(store.SearchMovies(source.Id, "Movie", null, 0, 10).Items).Id), 120, 1000);
        store.SaveVodProgress(store.ResolveEpisodeForPlayback(episodes[0].Id), 998, 1000);
        store.SaveVodProgress(store.ResolveEpisodeForPlayback(episodes[1].Id), 400, 1000);

        using (var connection = Open(database))
        using (var command = connection.CreateCommand())
        {
            command.CommandText = "DROP TABLE iptv_vod_continue_dismissals; PRAGMA user_version=5;";
            command.ExecuteNonQuery();
        }
        NewStore(database);

        Assert.Equal(6, Scalar(database, "PRAGMA user_version"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_channels"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_movies"));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_series"));
        Assert.Equal(3, Scalar(database, "SELECT COUNT(*) FROM iptv_series_episodes"));
        Assert.Equal(3, Scalar(database, "SELECT COUNT(*) FROM iptv_vod_progress"));
        Assert.Equal(1, Scalar(database, "SELECT completed FROM iptv_vod_progress WHERE media_type='episode' AND episode_number=1"));
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_vod_continue_dismissals"));
    }

    [Fact]
    public void MovieDismissalPreservesProgressSurvivesResyncAndNewActivityRestoresIt()
    {
        var now = DateTimeOffset.Parse("2026-10-20T12:00:00Z");
        var store = NewStore(DatabasePath(), () => now);
        var source = AddCatalog(store);
        var movie = Assert.Single(store.SearchMovies(source.Id, "Movie", null, 0, 10).Items);
        var playback = store.ResolveMovieForPlayback(movie.Id);
        store.SaveVodProgress(playback, 120, 1000);
        now = now.AddMinutes(1);

        Assert.True(store.DismissContinueWatching(source.Id, "movie", movie.Id));
        Assert.Empty(store.GetContinueWatchingMovies(source.Id));
        Assert.Equal(120, store.ResumePosition(playback));
        Assert.Equal(1, Scalar(DatabasePath(), "SELECT COUNT(*) FROM iptv_vod_progress"));

        store.ReplaceVodCatalog(source.Id, CatalogImport());
        var refreshed = Assert.Single(store.SearchMovies(source.Id, "Movie", null, 0, 10).Items);
        Assert.Empty(store.GetContinueWatchingMovies(source.Id));
        Assert.Equal(120, store.ResumePosition(store.ResolveMovieForPlayback(refreshed.Id)));

        now = now.AddMinutes(1);
        store.SaveVodProgress(store.ResolveMovieForPlayback(refreshed.Id), 200, 1000);
        Assert.Equal(refreshed.Id, Assert.Single(store.GetContinueWatchingMovies(source.Id)).Id);
    }

    [Fact]
    public void SeriesDismissalPreservesEpisodeHistorySurvivesRefreshAndNewActivityRestoresIt()
    {
        var now = DateTimeOffset.Parse("2026-10-20T12:00:00Z");
        var store = NewStore(DatabasePath(), () => now);
        var source = AddCatalog(store);
        var series = Assert.Single(store.SearchSeries(source.Id, "Series", null, 0, 10).Items);
        var episodes = store.ReplaceSeriesEpisodes(series.Id, Episodes()).Episodes;
        store.SaveVodProgress(store.ResolveEpisodeForPlayback(episodes[0].Id), 998, 1000);
        store.SaveVodProgress(store.ResolveEpisodeForPlayback(episodes[1].Id), 998, 1000);
        store.SaveVodProgress(store.ResolveEpisodeForPlayback(episodes[2].Id), 400, 1000);
        now = now.AddMinutes(1);

        Assert.True(store.DismissContinueWatching(source.Id, "series", series.Id));
        Assert.Empty(store.GetContinueWatchingSeries(source.Id));
        var preserved = store.GetSeriesDetails(series.Id).Episodes;
        Assert.True(preserved[0].Completed);
        Assert.True(preserved[1].Completed);
        Assert.Equal(400, preserved[2].Position);

        var refreshed = store.ReplaceSeriesEpisodes(series.Id, Episodes()).Episodes;
        Assert.Empty(store.GetContinueWatchingSeries(source.Id));
        Assert.True(refreshed[0].Completed);
        Assert.True(refreshed[1].Completed);
        Assert.Equal(400, refreshed[2].Position);

        now = now.AddMinutes(1);
        store.SaveVodProgress(store.ResolveEpisodeForPlayback(refreshed[2].Id), 500, 1000);
        Assert.Equal(series.Id, Assert.Single(store.GetContinueWatchingSeries(source.Id)).SeriesId);
    }

    [Fact]
    public void DismissalIsSourceFencedAndCascadesWithSource()
    {
        var database = DatabasePath();
        var store = NewStore(database);
        var source = AddCatalog(store);
        var movie = Assert.Single(store.SearchMovies(source.Id, "Movie", null, 0, 10).Items);
        var other = store.AddSource("Other", IptvSourceType.Xtream, "protected");

        Assert.False(store.DismissContinueWatching(other.Id, "movie", movie.Id));
        Assert.True(store.DismissContinueWatching(source.Id, "movie", movie.Id));
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_vod_continue_dismissals"));
        Assert.True(store.RemoveSource(source.Id));
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_vod_continue_dismissals"));
    }

    [Fact]
    public void MovieProgressUsesStableIdentitySurvivesRefreshRestartAndCompletionRules()
    {
        var database = DatabasePath();
        var store = NewStore(database);
        var source = AddCatalog(store);
        var original = Assert.Single(store.SearchMovies(source.Id, "Movie", null, 0, 10).Items);
        var item = store.ResolveMovieForPlayback(original.Id);

        store.SaveVodProgress(item, 1800, 7200);
        Assert.Equal(1800, store.ResumePosition(item));
        var continued = Assert.Single(store.GetContinueWatchingMovies(source.Id));
        Assert.Equal(original.Id, continued.Id);
        Assert.Equal(25, continued.ProgressPercent);

        store.ReplaceVodCatalog(source.Id, CatalogImport());
        var replacement = Assert.Single(store.SearchMovies(source.Id, "Movie", null, 0, 10).Items);
        Assert.NotEqual(original.Id, replacement.Id);
        Assert.Equal(replacement.Id, Assert.Single(store.GetContinueWatchingMovies(source.Id)).Id);

        var reopened = NewStore(database);
        Assert.Equal(1800, reopened.ResumePosition(reopened.ResolveMovieForPlayback(replacement.Id)));
        reopened.SaveVodProgress(reopened.ResolveMovieForPlayback(replacement.Id), 905, 1000);
        Assert.Empty(reopened.GetContinueWatchingMovies(source.Id));
        Assert.Equal(0, reopened.ResumePosition(reopened.ResolveMovieForPlayback(replacement.Id)));
        Assert.True(IptvStore.IsCompleted(900, 1000));
        Assert.True(IptvStore.IsCompleted(995, 1000));
        Assert.False(IptvStore.IsCompleted(899, 1000));
    }

    [Fact]
    public void ProgressBelowFiveSecondsDoesNotCreateHistory()
    {
        var store = NewStore(DatabasePath());
        var source = AddCatalog(store);
        store.SaveVodProgress(store.ResolveMovieForPlayback(Assert.Single(store.SearchMovies(source.Id, "Movie", null, 0, 10).Items).Id), 4.99, 1000);
        Assert.Empty(store.GetContinueWatchingMovies(source.Id));
    }

    [Fact]
    public void EpisodeProgressSurvivesEpisodeRefreshAndMarksBatchWatchState()
    {
        var store = NewStore(DatabasePath());
        var source = AddCatalog(store);
        var series = Assert.Single(store.SearchSeries(source.Id, "Series", null, 0, 10).Items);
        var initial = store.ReplaceSeriesEpisodes(series.Id, Episodes());
        var episode = initial.Episodes.Single(value => value.SeasonNumber == 1 && value.EpisodeNumber == 2);
        store.SaveVodProgress(store.ResolveEpisodeForPlayback(episode.Id), 900, 2400);

        var refreshed = store.ReplaceSeriesEpisodes(series.Id, Episodes());
        var sameEpisode = refreshed.Episodes.Single(value => value.SeasonNumber == 1 && value.EpisodeNumber == 2);
        Assert.NotEqual(episode.Id, sameEpisode.Id);
        Assert.Equal(900, sameEpisode.Position);
        Assert.Equal(37.5, sameEpisode.ProgressPercent);
        Assert.True(sameEpisode.ContinueHere);
        Assert.False(sameEpisode.Completed);

        store.SaveVodProgress(store.ResolveEpisodeForPlayback(sameEpisode.Id), 2397, 2400);
        Assert.True(store.GetSeriesDetails(series.Id).Episodes.Single(value => value.Id == sameEpisode.Id).Completed);
    }

    [Fact]
    public void ContinueSeriesUsesIncompleteEpisodeOrNextEpisodeAcrossSeasons()
    {
        var store = NewStore(DatabasePath());
        var source = AddCatalog(store);
        var series = Assert.Single(store.SearchSeries(source.Id, "Series", null, 0, 10).Items);
        var details = store.ReplaceSeriesEpisodes(series.Id, Episodes());
        var second = details.Episodes.Single(value => value.SeasonNumber == 1 && value.EpisodeNumber == 2);
        store.SaveVodProgress(store.ResolveEpisodeForPlayback(second.Id), 600, 2000);
        var partial = Assert.Single(store.GetContinueWatchingSeries(source.Id));
        Assert.Equal(second.Id, partial.EpisodeId);
        Assert.False(partial.CompletedPrevious);

        store.SaveVodProgress(store.ResolveEpisodeForPlayback(second.Id), 1998, 2000);
        var next = Assert.Single(store.GetContinueWatchingSeries(source.Id));
        Assert.Equal(2, next.SeasonNumber);
        Assert.Equal(1, next.EpisodeNumber);
        Assert.True(next.CompletedPrevious);
        Assert.Equal(0, next.Position);
    }

    [Fact]
    public void EpisodeNavigationCrossesSeasonAndHonorsBoundariesAndSourceFence()
    {
        var store = NewStore(DatabasePath());
        var source = AddCatalog(store);
        var series = Assert.Single(store.SearchSeries(source.Id, "Series", null, 0, 10).Items);
        var episodes = store.ReplaceSeriesEpisodes(series.Id, Episodes()).Episodes;
        var first = store.ResolveEpisodeForPlayback(episodes[0].Id);
        var second = store.ResolveEpisodeForPlayback(episodes[1].Id);
        var third = store.ResolveEpisodeForPlayback(episodes[2].Id);

        Assert.Null(store.NavigateEpisode(source.Id, first.StableKey, "previous"));
        Assert.Equal(third.StableKey, store.NavigateEpisode(source.Id, second.StableKey, "next")!.StableKey);
        Assert.Equal(second.StableKey, store.NavigateEpisode(source.Id, third.StableKey, "previous")!.StableKey);
        Assert.Null(store.NavigateEpisode(source.Id, third.StableKey, "next"));

        var other = store.AddSource("Other", IptvSourceType.Xtream, "protected");
        Assert.Null(store.NavigateEpisode(other.Id, second.StableKey, "next"));
    }

    [Fact]
    public void SourceRemovalCascadesProgress()
    {
        var database = DatabasePath();
        var store = NewStore(database);
        var source = AddCatalog(store);
        var movie = store.ResolveMovieForPlayback(Assert.Single(store.SearchMovies(source.Id, "Movie", null, 0, 10).Items).Id);
        store.SaveVodProgress(movie, 100, 1000);
        Assert.Equal(1, Scalar(database, "SELECT COUNT(*) FROM iptv_vod_progress"));
        Assert.True(store.RemoveSource(source.Id));
        Assert.Equal(0, Scalar(database, "SELECT COUNT(*) FROM iptv_vod_progress"));
    }

    private IptvStore NewStore(string database, Func<DateTimeOffset>? utcNow = null)
    {
        var store = new IptvStore(database, _protector, utcNow);
        store.Initialize();
        return store;
    }

    private static IptvSourceSummary AddCatalog(IptvStore store)
    {
        var source = store.AddSource("Provider", IptvSourceType.Xtream, "protected");
        return store.ReplaceVodCatalog(source.Id, CatalogImport());
    }

    private static XtreamVodImport CatalogImport() => new([],
        [new XtreamMovie("200", "Movie", null, null, "mp4", null, null, null, null, null, null)],
        [new XtreamSeries("500", "Series", null, null, null, null, null, null, null, null)]);

    private static XtreamEpisode[] Episodes() =>
    [
        new("301", 1, 1, "One", "mkv", null, null, null),
        new("302", 1, 2, "Two", "mkv", null, null, null),
        new("401", 2, 1, "Three", "mkv", null, null, null),
    ];

    private static async IAsyncEnumerable<ParsedM3uChannel> LiveChannels()
    {
        yield return new ParsedM3uChannel("Live", "https://example.invalid/live", "live-1", null, "Live");
        await Task.CompletedTask;
    }

    public void Dispose() { if (Directory.Exists(_directory)) Directory.Delete(_directory, true); }
    private string DatabasePath() { Directory.CreateDirectory(_directory); return Path.Combine(_directory, "semyra.db"); }
    private static SqliteConnection Open(string path) { var connection = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = path, Pooling = false }.ToString()); connection.Open(); return connection; }
    private static long Scalar(string path, string sql) { using var connection = Open(path); using var command = connection.CreateCommand(); command.CommandText = sql; return Convert.ToInt64(command.ExecuteScalar()); }
    private static string Text(string path, string sql) { using var connection = Open(path); using var command = connection.CreateCommand(); command.CommandText = sql; return Convert.ToString(command.ExecuteScalar())!; }
    private sealed class TestProtector : ISecretProtector { public byte[] Protect(string value) => Encoding.UTF8.GetBytes(value); public string Unprotect(byte[] value) => Encoding.UTF8.GetString(value); }
}
