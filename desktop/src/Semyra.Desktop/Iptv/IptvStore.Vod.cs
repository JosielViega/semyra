using System.Globalization;
using Microsoft.Data.Sqlite;

namespace Semyra.Desktop.Iptv;

public sealed partial class IptvStore
{
    private static void MigrateVersionThreeToFour(SqliteConnection connection)
    {
        using var transaction = connection.BeginTransaction();
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            CREATE TABLE IF NOT EXISTS iptv_vod_sync (
                source_id INTEGER PRIMARY KEY,
                state TEXT NOT NULL DEFAULT 'idle' CHECK (state IN ('idle','syncing','ready','error')),
                error TEXT NULL,
                last_synced_at TEXT NULL,
                movie_count INTEGER NOT NULL DEFAULT 0,
                series_count INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS iptv_vod_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                media_type TEXT NOT NULL CHECK (media_type IN ('movie','series')),
                external_category_id TEXT NOT NULL,
                name TEXT NOT NULL,
                display_order INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                UNIQUE (source_id, media_type, external_category_id)
            );
            CREATE INDEX IF NOT EXISTS idx_vod_categories_source_type_order ON iptv_vod_categories(source_id, media_type, display_order, name COLLATE NOCASE);
            CREATE TABLE IF NOT EXISTS iptv_movies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                external_stream_id TEXT NOT NULL,
                stable_key TEXT NOT NULL,
                external_category_id TEXT NULL,
                name TEXT NOT NULL,
                poster_url TEXT NULL,
                container_extension TEXT NULL,
                year TEXT NULL,
                rating TEXT NULL,
                plot TEXT NULL,
                genre TEXT NULL,
                release_date TEXT NULL,
                added TEXT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                UNIQUE (source_id, external_stream_id),
                UNIQUE (source_id, stable_key)
            );
            CREATE INDEX IF NOT EXISTS idx_movies_source_name ON iptv_movies(source_id, name COLLATE NOCASE);
            CREATE INDEX IF NOT EXISTS idx_movies_source_category ON iptv_movies(source_id, external_category_id);
            CREATE TABLE IF NOT EXISTS iptv_series (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                external_series_id TEXT NOT NULL,
                stable_key TEXT NOT NULL,
                external_category_id TEXT NULL,
                name TEXT NOT NULL,
                poster_url TEXT NULL,
                year TEXT NULL,
                rating TEXT NULL,
                plot TEXT NULL,
                genre TEXT NULL,
                release_date TEXT NULL,
                added TEXT NULL,
                episodes_synced_at TEXT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                UNIQUE (source_id, external_series_id),
                UNIQUE (source_id, stable_key)
            );
            CREATE INDEX IF NOT EXISTS idx_series_source_name ON iptv_series(source_id, name COLLATE NOCASE);
            CREATE INDEX IF NOT EXISTS idx_series_source_category ON iptv_series(source_id, external_category_id);
            CREATE TABLE IF NOT EXISTS iptv_series_episodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                series_id INTEGER NOT NULL,
                external_episode_id TEXT NOT NULL,
                season_number INTEGER NOT NULL CHECK (season_number >= 0),
                episode_number INTEGER NULL,
                name TEXT NOT NULL,
                container_extension TEXT NULL,
                duration TEXT NULL,
                plot TEXT NULL,
                added TEXT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                FOREIGN KEY (series_id) REFERENCES iptv_series(id) ON DELETE CASCADE,
                UNIQUE (series_id, external_episode_id)
            );
            CREATE INDEX IF NOT EXISTS idx_episodes_series_order ON iptv_series_episodes(series_id, season_number, episode_number, id);
            PRAGMA user_version = 4;
            """;
        command.ExecuteNonQuery();
        transaction.Commit();
    }

    public void MarkVodSyncing(long sourceId) => UpdateVodState(sourceId, "syncing", null);
    public void MarkVodSyncError(long sourceId, string error) => UpdateVodState(sourceId, "error", error);

    private void UpdateVodState(long sourceId, string state, string? error)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            INSERT INTO iptv_vod_sync(source_id,state,error,updated_at)
            SELECT id,$state,$error,$now FROM iptv_sources WHERE id=$source
            ON CONFLICT(source_id) DO UPDATE SET state=excluded.state,error=excluded.error,updated_at=excluded.updated_at
            """;
        command.Parameters.AddWithValue("$source", sourceId);
        command.Parameters.AddWithValue("$state", state);
        command.Parameters.AddWithValue("$error", (object?)error ?? DBNull.Value);
        command.Parameters.AddWithValue("$now", _utcNow().ToString("O", CultureInfo.InvariantCulture));
        command.ExecuteNonQuery();
    }

    internal IptvSourceSummary ReplaceVodCatalog(long sourceId, XtreamVodImport import)
    {
        using var connection = Open();
        using var transaction = connection.BeginTransaction();
        Execute(connection, transaction, "DELETE FROM iptv_vod_categories WHERE source_id=$source; DELETE FROM iptv_movies WHERE source_id=$source; DELETE FROM iptv_series WHERE source_id=$source;", sourceId);

        using (var insert = connection.CreateCommand())
        {
            insert.Transaction = transaction;
            insert.CommandText = "INSERT OR IGNORE INTO iptv_vod_categories(source_id,media_type,external_category_id,name,display_order) VALUES($s,$t,$id,$n,$o)";
            var t = insert.Parameters.Add("$t", SqliteType.Text); var id = insert.Parameters.Add("$id", SqliteType.Text); var n = insert.Parameters.Add("$n", SqliteType.Text); var o = insert.Parameters.Add("$o", SqliteType.Integer); insert.Parameters.AddWithValue("$s", sourceId);
            foreach (var item in import.Categories) { t.Value = VodType(item.MediaType); id.Value = item.Id; n.Value = item.Name; o.Value = item.DisplayOrder; insert.ExecuteNonQuery(); }
        }
        using (var insert = connection.CreateCommand())
        {
            insert.Transaction = transaction;
            insert.CommandText = "INSERT OR IGNORE INTO iptv_movies(source_id,external_stream_id,stable_key,external_category_id,name,poster_url,container_extension,year,rating,plot,genre,release_date,added) VALUES($s,$id,$key,$c,$n,$p,$e,$y,$r,$d,$g,$rd,$a)";
            AddVodParameters(insert, sourceId);
            foreach (var item in import.Movies) { BindVod(insert, item.StreamId, "xtream:movie:" + item.StreamId, item.CategoryId, item.Name, item.PosterUrl, item.ContainerExtension, item.Year, item.Rating, item.Plot, item.Genre, item.ReleaseDate, item.Added); insert.ExecuteNonQuery(); }
        }
        using (var insert = connection.CreateCommand())
        {
            insert.Transaction = transaction;
            insert.CommandText = "INSERT OR IGNORE INTO iptv_series(source_id,external_series_id,stable_key,external_category_id,name,poster_url,year,rating,plot,genre,release_date,added) VALUES($s,$id,$key,$c,$n,$p,$y,$r,$d,$g,$rd,$a)";
            AddVodParameters(insert, sourceId, false);
            foreach (var item in import.Series) { BindVod(insert, item.SeriesId, "xtream:series:" + item.SeriesId, item.CategoryId, item.Name, item.PosterUrl, null, item.Year, item.Rating, item.Plot, item.Genre, item.ReleaseDate, item.Added); insert.ExecuteNonQuery(); }
        }
        var now = _utcNow().ToString("O", CultureInfo.InvariantCulture);
        using (var done = connection.CreateCommand())
        {
            done.Transaction = transaction; done.CommandText = "INSERT INTO iptv_vod_sync(source_id,state,error,last_synced_at,movie_count,series_count,updated_at) VALUES($s,'ready',NULL,$now,$m,$series,$now) ON CONFLICT(source_id) DO UPDATE SET state='ready',error=NULL,last_synced_at=excluded.last_synced_at,movie_count=excluded.movie_count,series_count=excluded.series_count,updated_at=excluded.updated_at";
            done.Parameters.AddWithValue("$s", sourceId); done.Parameters.AddWithValue("$now", now); done.Parameters.AddWithValue("$m", import.Movies.Count); done.Parameters.AddWithValue("$series", import.Series.Count); done.ExecuteNonQuery();
        }
        transaction.Commit();
        return FindSummary(connection, sourceId) ?? throw new IptvValidationException("Fonte não encontrada.");
    }

    public IReadOnlyList<IptvVodCategorySummary> GetVodCategories(long sourceId, IptvVodMediaType type)
    {
        using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = "SELECT id,media_type,external_category_id,name,display_order FROM iptv_vod_categories WHERE source_id=$s AND media_type=$t ORDER BY display_order,name COLLATE NOCASE"; cmd.Parameters.AddWithValue("$s", sourceId); cmd.Parameters.AddWithValue("$t", VodType(type)); using var r = cmd.ExecuteReader(); var list = new List<IptvVodCategorySummary>(); while (r.Read()) list.Add(new(r.GetInt64(0), r.GetString(1), r.GetString(2), r.GetString(3), r.GetInt32(4))); return list;
    }

    public IptvVodSearchResult<IptvMovieSummary> SearchMovies(long sourceId, string query, string? category, int offset, int limit)
    {
        using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = @"SELECT id,name,external_category_id,poster_url,year,rating,genre FROM iptv_movies WHERE source_id=$s AND ($q='' OR name LIKE $p ESCAPE '\') AND ($c IS NULL OR external_category_id=$c) ORDER BY name COLLATE NOCASE,id LIMIT $l OFFSET $o"; BindSearch(cmd, sourceId, query, category, offset, limit); using var r = cmd.ExecuteReader(); var list = new List<IptvMovieSummary>(); while (r.Read()) list.Add(new(r.GetInt64(0), r.GetString(1), Null(r, 2), Null(r, 3), Null(r, 4), Null(r, 5), Null(r, 6))); var more = list.Count > limit; if (more) list.RemoveAt(list.Count - 1); return new(list, offset, limit, more);
    }

    public IptvVodSearchResult<IptvSeriesSummary> SearchSeries(long sourceId, string query, string? category, int offset, int limit)
    {
        using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = @"SELECT id,name,external_category_id,poster_url,year,rating,genre FROM iptv_series WHERE source_id=$s AND ($q='' OR name LIKE $p ESCAPE '\') AND ($c IS NULL OR external_category_id=$c) ORDER BY name COLLATE NOCASE,id LIMIT $l OFFSET $o"; BindSearch(cmd, sourceId, query, category, offset, limit); using var r = cmd.ExecuteReader(); var list = new List<IptvSeriesSummary>(); while (r.Read()) list.Add(new(r.GetInt64(0), r.GetString(1), Null(r, 2), Null(r, 3), Null(r, 4), Null(r, 5), Null(r, 6))); var more = list.Count > limit; if (more) list.RemoveAt(list.Count - 1); return new(list, offset, limit, more);
    }

    internal (long SourceId, string ExternalSeriesId) FindSeriesIdentity(long seriesId)
    { using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = "SELECT source_id,external_series_id FROM iptv_series WHERE id=$id"; cmd.Parameters.AddWithValue("$id", seriesId); using var r = cmd.ExecuteReader(); return r.Read() ? (r.GetInt64(0), r.GetString(1)) : throw new IptvValidationException("Série não encontrada."); }

    internal IptvVodPlaybackItem ResolveMovieForPlayback(long id) => ResolveVodItem("iptv_movies", "external_stream_id", "movie", id);
    internal IptvVodPlaybackItem ResolveEpisodeForPlayback(long id) => ResolveVodItem("iptv_series_episodes", "external_episode_id", "series", id);
    private IptvVodPlaybackItem ResolveVodItem(string table, string externalColumn, string kind, long id)
    { using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = $"SELECT id,source_id,name,{externalColumn},container_extension FROM {table} WHERE id=$id"; cmd.Parameters.AddWithValue("$id", id); using var r = cmd.ExecuteReader(); return r.Read() ? new(r.GetInt64(0), r.GetInt64(1), r.GetString(2), r.GetString(3), Null(r, 4), kind) : throw new IptvValidationException("Conteúdo não encontrado."); }

    internal IptvSeriesDetails ReplaceSeriesEpisodes(long seriesId, IReadOnlyList<XtreamEpisode> episodes)
    {
        using var c = Open(); using var tx = c.BeginTransaction(); long source; using (var find = c.CreateCommand()) { find.Transaction = tx; find.CommandText = "SELECT source_id FROM iptv_series WHERE id=$id"; find.Parameters.AddWithValue("$id", seriesId); source = Convert.ToInt64(find.ExecuteScalar() ?? throw new IptvValidationException("Série não encontrada."), CultureInfo.InvariantCulture); }
        using (var del = c.CreateCommand()) { del.Transaction = tx; del.CommandText = "DELETE FROM iptv_series_episodes WHERE series_id=$id"; del.Parameters.AddWithValue("$id", seriesId); del.ExecuteNonQuery(); }
        using (var ins = c.CreateCommand()) { ins.Transaction = tx; ins.CommandText = "INSERT OR IGNORE INTO iptv_series_episodes(source_id,series_id,external_episode_id,season_number,episode_number,name,container_extension,duration,plot,added) VALUES($s,$series,$id,$season,$episode,$n,$e,$duration,$plot,$added)"; foreach (var p in new[] { "$s", "$series", "$id", "$season", "$episode", "$n", "$e", "$duration", "$plot", "$added" }) ins.Parameters.Add(new SqliteParameter(p, SqliteType.Text)); foreach (var x in episodes) { ins.Parameters["$s"].Value = source; ins.Parameters["$series"].Value = seriesId; ins.Parameters["$id"].Value = x.EpisodeId; ins.Parameters["$season"].Value = x.SeasonNumber; ins.Parameters["$episode"].Value = (object?)x.EpisodeNumber ?? DBNull.Value; ins.Parameters["$n"].Value = x.Name; ins.Parameters["$e"].Value = (object?)x.ContainerExtension ?? DBNull.Value; ins.Parameters["$duration"].Value = (object?)x.Duration ?? DBNull.Value; ins.Parameters["$plot"].Value = (object?)x.Plot ?? DBNull.Value; ins.Parameters["$added"].Value = (object?)x.Added ?? DBNull.Value; ins.ExecuteNonQuery(); } }
        var now = _utcNow().ToString("O", CultureInfo.InvariantCulture); using (var up = c.CreateCommand()) { up.Transaction = tx; up.CommandText = "UPDATE iptv_series SET episodes_synced_at=$now WHERE id=$id"; up.Parameters.AddWithValue("$now", now); up.Parameters.AddWithValue("$id", seriesId); up.ExecuteNonQuery(); }
        tx.Commit(); return GetSeriesDetails(seriesId);
    }

    public IptvSeriesDetails GetSeriesDetails(long seriesId)
    { using var c = Open(); DateTimeOffset? synced = null; using (var h = c.CreateCommand()) { h.CommandText = "SELECT episodes_synced_at FROM iptv_series WHERE id=$id"; h.Parameters.AddWithValue("$id", seriesId); var raw = h.ExecuteScalar(); if (raw is null) throw new IptvValidationException("Série não encontrada."); if (raw is string value && DateTimeOffset.TryParse(value, CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind, out var dt)) synced = dt; } using var cmd = c.CreateCommand(); cmd.CommandText = "SELECT id,season_number,episode_number,name,duration FROM iptv_series_episodes WHERE series_id=$id ORDER BY season_number,episode_number,id"; cmd.Parameters.AddWithValue("$id", seriesId); using var r = cmd.ExecuteReader(); var items = new List<IptvEpisodeSummary>(); while (r.Read()) items.Add(new(r.GetInt64(0), r.GetInt32(1), r.IsDBNull(2) ? null : r.GetInt32(2), r.GetString(3), Null(r, 4))); return new(seriesId, items, synced); }

    private static void Execute(SqliteConnection c, SqliteTransaction tx, string sql, long source) { using var cmd = c.CreateCommand(); cmd.Transaction = tx; cmd.CommandText = sql; cmd.Parameters.AddWithValue("$source", source); cmd.ExecuteNonQuery(); }
    private static void AddVodParameters(SqliteCommand cmd, long source, bool extension = true) { cmd.Parameters.AddWithValue("$s", source); foreach (var p in new[] { "$id", "$key", "$c", "$n", "$p", "$y", "$r", "$d", "$g", "$rd", "$a" }) cmd.Parameters.Add(new SqliteParameter(p, SqliteType.Text)); if (extension) cmd.Parameters.Add(new SqliteParameter("$e", SqliteType.Text)); }
    private static void BindVod(SqliteCommand cmd, string id, string key, string? category, string name, string? poster, string? extension, string? year, string? rating, string? plot, string? genre, string? release, string? added) { cmd.Parameters["$id"].Value = id; cmd.Parameters["$key"].Value = key; cmd.Parameters["$c"].Value = (object?)category ?? DBNull.Value; cmd.Parameters["$n"].Value = name; cmd.Parameters["$p"].Value = (object?)poster ?? DBNull.Value; if (cmd.Parameters.Contains("$e")) cmd.Parameters["$e"].Value = (object?)extension ?? DBNull.Value; cmd.Parameters["$y"].Value = (object?)year ?? DBNull.Value; cmd.Parameters["$r"].Value = (object?)rating ?? DBNull.Value; cmd.Parameters["$d"].Value = (object?)plot ?? DBNull.Value; cmd.Parameters["$g"].Value = (object?)genre ?? DBNull.Value; cmd.Parameters["$rd"].Value = (object?)release ?? DBNull.Value; cmd.Parameters["$a"].Value = (object?)added ?? DBNull.Value; }
    private static void BindSearch(SqliteCommand cmd, long source, string query, string? category, int offset, int limit) { cmd.Parameters.AddWithValue("$s", source); cmd.Parameters.AddWithValue("$q", query); cmd.Parameters.AddWithValue("$p", $"%{EscapeLike(query)}%"); cmd.Parameters.AddWithValue("$c", (object?)category ?? DBNull.Value); cmd.Parameters.AddWithValue("$o", offset); cmd.Parameters.AddWithValue("$l", limit + 1); }
    private static string? Null(SqliteDataReader r, int i) => r.IsDBNull(i) ? null : r.GetString(i);
    private static string VodType(IptvVodMediaType type) => type == IptvVodMediaType.Movie ? "movie" : "series";
}
