using System.Globalization;
using Microsoft.Data.Sqlite;

namespace Semyra.Desktop.Iptv;

public sealed partial class IptvStore
{
    private static void MigrateVersionFiveToSix(SqliteConnection connection)
    {
        using var transaction = connection.BeginTransaction();
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            CREATE TABLE iptv_vod_continue_dismissals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                media_type TEXT NOT NULL CHECK (media_type IN ('movie','series')),
                stable_key TEXT NOT NULL,
                dismissed_at TEXT NOT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                UNIQUE (source_id, media_type, stable_key)
            );
            CREATE INDEX idx_vod_continue_dismissals_source
                ON iptv_vod_continue_dismissals(source_id, media_type, stable_key);
            PRAGMA user_version = 6;
            """;
        command.ExecuteNonQuery();
        transaction.Commit();
    }

    private static void MigrateVersionFourToFive(SqliteConnection connection)
    {
        using var transaction = connection.BeginTransaction();
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            CREATE TABLE iptv_series_episodes_v5 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                series_id INTEGER NOT NULL,
                external_episode_id TEXT NOT NULL,
                stable_key TEXT NOT NULL,
                season_number INTEGER NOT NULL CHECK (season_number >= 0),
                episode_number INTEGER NULL,
                name TEXT NOT NULL,
                container_extension TEXT NULL,
                duration TEXT NULL,
                plot TEXT NULL,
                added TEXT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                FOREIGN KEY (series_id) REFERENCES iptv_series(id) ON DELETE CASCADE,
                UNIQUE (series_id, external_episode_id),
                UNIQUE (source_id, stable_key)
            );
            INSERT INTO iptv_series_episodes_v5
                (id,source_id,series_id,external_episode_id,stable_key,season_number,episode_number,name,container_extension,duration,plot,added)
            SELECT e.id,e.source_id,e.series_id,e.external_episode_id,
                   'xtream:episode:' || s.external_series_id || ':' || e.external_episode_id,
                   e.season_number,e.episode_number,e.name,e.container_extension,e.duration,e.plot,e.added
              FROM iptv_series_episodes e
              JOIN iptv_series s ON s.id=e.series_id;
            DROP TABLE iptv_series_episodes;
            ALTER TABLE iptv_series_episodes_v5 RENAME TO iptv_series_episodes;
            CREATE INDEX idx_episodes_series_order ON iptv_series_episodes(series_id, season_number, episode_number, id);
            CREATE INDEX idx_episodes_source_stable ON iptv_series_episodes(source_id, stable_key);
            CREATE TABLE iptv_vod_progress (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                media_type TEXT NOT NULL CHECK (media_type IN ('movie','episode')),
                stable_key TEXT NOT NULL,
                series_stable_key TEXT NULL,
                season_number INTEGER NULL,
                episode_number INTEGER NULL,
                position_seconds REAL NOT NULL DEFAULT 0,
                duration_seconds REAL NOT NULL DEFAULT 0,
                completed INTEGER NOT NULL DEFAULT 0 CHECK (completed IN (0,1)),
                last_watched_at TEXT NOT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                UNIQUE (source_id, media_type, stable_key)
            );
            CREATE INDEX idx_vod_progress_source_watched ON iptv_vod_progress(source_id, last_watched_at DESC, id DESC);
            CREATE INDEX idx_vod_progress_source_stable ON iptv_vod_progress(source_id, stable_key);
            CREATE INDEX idx_vod_progress_source_series ON iptv_vod_progress(source_id, series_stable_key, last_watched_at DESC);
            PRAGMA user_version = 5;
            """;
        command.ExecuteNonQuery();
        transaction.Commit();
    }
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
        using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = @"SELECT id,name,external_category_id,poster_url,year,rating,genre,plot,release_date FROM iptv_movies WHERE source_id=$s AND ($q='' OR name LIKE $p ESCAPE '\') AND ($c IS NULL OR external_category_id=$c) ORDER BY name COLLATE NOCASE,id LIMIT $l OFFSET $o"; BindSearch(cmd, sourceId, query, category, offset, limit); using var r = cmd.ExecuteReader(); var list = new List<IptvMovieSummary>(); while (r.Read()) list.Add(new(r.GetInt64(0), r.GetString(1), Null(r, 2), Null(r, 3), Null(r, 4), Null(r, 5), Null(r, 6), Null(r, 7), Null(r, 8))); var more = list.Count > limit; if (more) list.RemoveAt(list.Count - 1); return new(list, offset, limit, more);
    }

    public IptvVodSearchResult<IptvSeriesSummary> SearchSeries(long sourceId, string query, string? category, int offset, int limit)
    {
        using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = @"SELECT id,name,external_category_id,poster_url,year,rating,genre,plot,release_date FROM iptv_series WHERE source_id=$s AND ($q='' OR name LIKE $p ESCAPE '\') AND ($c IS NULL OR external_category_id=$c) ORDER BY name COLLATE NOCASE,id LIMIT $l OFFSET $o"; BindSearch(cmd, sourceId, query, category, offset, limit); using var r = cmd.ExecuteReader(); var list = new List<IptvSeriesSummary>(); while (r.Read()) list.Add(new(r.GetInt64(0), r.GetString(1), Null(r, 2), Null(r, 3), Null(r, 4), Null(r, 5), Null(r, 6), Null(r, 7), Null(r, 8))); var more = list.Count > limit; if (more) list.RemoveAt(list.Count - 1); return new(list, offset, limit, more);
    }

    internal (long SourceId, string ExternalSeriesId) FindSeriesIdentity(long seriesId)
    { using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = "SELECT source_id,external_series_id FROM iptv_series WHERE id=$id"; cmd.Parameters.AddWithValue("$id", seriesId); using var r = cmd.ExecuteReader(); return r.Read() ? (r.GetInt64(0), r.GetString(1)) : throw new IptvValidationException("Série não encontrada."); }

    internal IptvVodPlaybackItem ResolveMovieForPlayback(long id) => ResolveVodItem("iptv_movies", "external_stream_id", "movie", id);
    internal IptvVodPlaybackItem ResolveEpisodeForPlayback(long id)
    { using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = "SELECT e.id,e.source_id,s.name || ' · S' || printf('%02d',e.season_number) || 'E' || printf('%02d',COALESCE(e.episode_number,0)) || ' — ' || e.name,e.external_episode_id,e.container_extension,e.stable_key,s.stable_key,e.series_id,e.season_number,e.episode_number FROM iptv_series_episodes e JOIN iptv_series s ON s.id=e.series_id WHERE e.id=$id"; cmd.Parameters.AddWithValue("$id", id); using var r = cmd.ExecuteReader(); return r.Read() ? new(r.GetInt64(0), r.GetInt64(1), r.GetString(2), r.GetString(3), Null(r, 4), "series", r.GetString(5), r.GetString(6), r.GetInt64(7), r.GetInt32(8), r.IsDBNull(9) ? null : r.GetInt32(9)) : throw new IptvValidationException("Conteúdo não encontrado."); }
    private IptvVodPlaybackItem ResolveVodItem(string table, string externalColumn, string kind, long id)
    { using var c = Open(); using var cmd = c.CreateCommand(); cmd.CommandText = $"SELECT id,source_id,name,{externalColumn},container_extension,stable_key FROM {table} WHERE id=$id"; cmd.Parameters.AddWithValue("$id", id); using var r = cmd.ExecuteReader(); return r.Read() ? new(r.GetInt64(0), r.GetInt64(1), r.GetString(2), r.GetString(3), Null(r, 4), kind, r.GetString(5)) : throw new IptvValidationException("Conteúdo não encontrado."); }

    internal IptvSeriesDetails ReplaceSeriesEpisodes(long seriesId, IReadOnlyList<XtreamEpisode> episodes)
    {
        using var c = Open(); using var tx = c.BeginTransaction(); long source; using (var find = c.CreateCommand()) { find.Transaction = tx; find.CommandText = "SELECT source_id FROM iptv_series WHERE id=$id"; find.Parameters.AddWithValue("$id", seriesId); source = Convert.ToInt64(find.ExecuteScalar() ?? throw new IptvValidationException("Série não encontrada."), CultureInfo.InvariantCulture); }
        string externalSeries; using (var series = c.CreateCommand()) { series.Transaction = tx; series.CommandText = "SELECT external_series_id FROM iptv_series WHERE id=$id"; series.Parameters.AddWithValue("$id", seriesId); externalSeries = Convert.ToString(series.ExecuteScalar(), CultureInfo.InvariantCulture) ?? throw new IptvValidationException("Série não encontrada."); }
        using (var del = c.CreateCommand()) { del.Transaction = tx; del.CommandText = "DELETE FROM iptv_series_episodes WHERE series_id=$id"; del.Parameters.AddWithValue("$id", seriesId); del.ExecuteNonQuery(); }
        using (var ins = c.CreateCommand()) { ins.Transaction = tx; ins.CommandText = "INSERT OR IGNORE INTO iptv_series_episodes(source_id,series_id,external_episode_id,stable_key,season_number,episode_number,name,container_extension,duration,plot,added) VALUES($s,$series,$id,$key,$season,$episode,$n,$e,$duration,$plot,$added)"; foreach (var p in new[] { "$s", "$series", "$id", "$key", "$season", "$episode", "$n", "$e", "$duration", "$plot", "$added" }) ins.Parameters.Add(new SqliteParameter(p, SqliteType.Text)); foreach (var x in episodes) { ins.Parameters["$s"].Value = source; ins.Parameters["$series"].Value = seriesId; ins.Parameters["$id"].Value = x.EpisodeId; ins.Parameters["$key"].Value = "xtream:episode:" + externalSeries + ":" + x.EpisodeId; ins.Parameters["$season"].Value = x.SeasonNumber; ins.Parameters["$episode"].Value = (object?)x.EpisodeNumber ?? DBNull.Value; ins.Parameters["$n"].Value = x.Name; ins.Parameters["$e"].Value = (object?)x.ContainerExtension ?? DBNull.Value; ins.Parameters["$duration"].Value = (object?)x.Duration ?? DBNull.Value; ins.Parameters["$plot"].Value = (object?)x.Plot ?? DBNull.Value; ins.Parameters["$added"].Value = (object?)x.Added ?? DBNull.Value; ins.ExecuteNonQuery(); } }
        var now = _utcNow().ToString("O", CultureInfo.InvariantCulture); using (var up = c.CreateCommand()) { up.Transaction = tx; up.CommandText = "UPDATE iptv_series SET episodes_synced_at=$now WHERE id=$id"; up.Parameters.AddWithValue("$now", now); up.Parameters.AddWithValue("$id", seriesId); up.ExecuteNonQuery(); }
        tx.Commit(); return GetSeriesDetails(seriesId);
    }

    public IptvSeriesDetails GetSeriesDetails(long seriesId)
    { using var c = Open(); DateTimeOffset? synced = null; using (var h = c.CreateCommand()) { h.CommandText = "SELECT episodes_synced_at FROM iptv_series WHERE id=$id"; h.Parameters.AddWithValue("$id", seriesId); var raw = h.ExecuteScalar(); if (raw is null) throw new IptvValidationException("Série não encontrada."); if (raw is string value && DateTimeOffset.TryParse(value, CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind, out var dt)) synced = dt; } using var cmd = c.CreateCommand(); cmd.CommandText = "SELECT e.id,e.season_number,e.episode_number,e.name,e.duration,e.plot,COALESCE(p.position_seconds,0),COALESCE(p.duration_seconds,0),COALESCE(p.completed,0),p.last_watched_at FROM iptv_series_episodes e LEFT JOIN iptv_vod_progress p ON p.source_id=e.source_id AND p.media_type='episode' AND p.stable_key=e.stable_key WHERE e.series_id=$id ORDER BY e.season_number,e.episode_number,e.id"; cmd.Parameters.AddWithValue("$id", seriesId); using var r = cmd.ExecuteReader(); var rawItems = new List<(IptvEpisodeSummary Item,string? Watched)>(); while (r.Read()) { var position=r.GetDouble(6); var duration=r.GetDouble(7); rawItems.Add((new(r.GetInt64(0), r.GetInt32(1), r.IsDBNull(2) ? null : r.GetInt32(2), r.GetString(3), Null(r, 4), Null(r, 5), position, Percent(position,duration), r.GetInt64(8)==1), r.IsDBNull(9)?null:r.GetString(9))); } var continueId=rawItems.Where(x=>!x.Item.Completed&&x.Item.Position>=5).OrderByDescending(x=>x.Watched).Select(x=>x.Item.Id).FirstOrDefault(); return new(seriesId, rawItems.Select(x=>x.Item with { ContinueHere=x.Item.Id==continueId }).ToArray(), synced); }

    internal bool SaveVodProgress(IptvVodPlaybackItem item, double position, double duration)
    {
        if (!double.IsFinite(position) || !double.IsFinite(duration) || position < 5 || duration <= 0) return false;
        position = Math.Clamp(position, 0, duration); var completed = IsCompleted(position, duration);
        using var c=Open(); using var cmd=c.CreateCommand(); cmd.CommandText="INSERT INTO iptv_vod_progress(source_id,media_type,stable_key,series_stable_key,season_number,episode_number,position_seconds,duration_seconds,completed,last_watched_at) VALUES($s,$t,$k,$series,$season,$episode,$p,$d,$completed,$now) ON CONFLICT(source_id,media_type,stable_key) DO UPDATE SET series_stable_key=excluded.series_stable_key,season_number=excluded.season_number,episode_number=excluded.episode_number,position_seconds=excluded.position_seconds,duration_seconds=excluded.duration_seconds,completed=excluded.completed,last_watched_at=excluded.last_watched_at";
        cmd.Parameters.AddWithValue("$s",item.SourceId); cmd.Parameters.AddWithValue("$t",item.Kind=="movie"?"movie":"episode"); cmd.Parameters.AddWithValue("$k",item.StableKey); cmd.Parameters.AddWithValue("$series",(object?)item.SeriesStableKey??DBNull.Value); cmd.Parameters.AddWithValue("$season",(object?)item.SeasonNumber??DBNull.Value); cmd.Parameters.AddWithValue("$episode",(object?)item.EpisodeNumber??DBNull.Value); cmd.Parameters.AddWithValue("$p",position); cmd.Parameters.AddWithValue("$d",duration); cmd.Parameters.AddWithValue("$completed",completed?1:0); cmd.Parameters.AddWithValue("$now",_utcNow().ToString("O",CultureInfo.InvariantCulture)); return cmd.ExecuteNonQuery() == 1;
    }

    internal double ResumePosition(IptvVodPlaybackItem item)
    { using var c=Open(); using var cmd=c.CreateCommand(); cmd.CommandText="SELECT position_seconds,duration_seconds,completed FROM iptv_vod_progress WHERE source_id=$s AND media_type=$t AND stable_key=$k"; cmd.Parameters.AddWithValue("$s",item.SourceId); cmd.Parameters.AddWithValue("$t",item.Kind=="movie"?"movie":"episode"); cmd.Parameters.AddWithValue("$k",item.StableKey); using var r=cmd.ExecuteReader(); if(!r.Read()) return 0; var p=r.GetDouble(0); var d=r.GetDouble(1); return r.GetInt64(2)==0&&p>=5&&d>0&&p<d-5?Math.Clamp(p,0,Math.Max(0,d-5)):0; }

    public IReadOnlyList<IptvContinueMovie> GetContinueWatchingMovies(long sourceId, int limit=20)
    { using var c=Open(); using var cmd=c.CreateCommand(); cmd.CommandText="SELECT m.id,m.name,m.poster_url,m.year,p.position_seconds,p.duration_seconds,p.last_watched_at FROM iptv_vod_progress p JOIN iptv_movies m ON m.source_id=p.source_id AND m.stable_key=p.stable_key LEFT JOIN iptv_vod_continue_dismissals d ON d.source_id=p.source_id AND d.media_type='movie' AND d.stable_key=p.stable_key WHERE p.source_id=$s AND p.media_type='movie' AND p.completed=0 AND p.position_seconds>=5 AND p.duration_seconds>0 AND (d.id IS NULL OR p.last_watched_at>d.dismissed_at) ORDER BY p.last_watched_at DESC LIMIT $l"; cmd.Parameters.AddWithValue("$s",sourceId);cmd.Parameters.AddWithValue("$l",Math.Clamp(limit,1,20));using var r=cmd.ExecuteReader();var list=new List<IptvContinueMovie>();while(r.Read()){var p=r.GetDouble(4);var d=r.GetDouble(5);list.Add(new(r.GetInt64(0),r.GetString(1),Null(r,2),Null(r,3),p,d,Percent(p,d),DateTimeOffset.Parse(r.GetString(6),CultureInfo.InvariantCulture,DateTimeStyles.RoundtripKind)));}return list; }

    public IReadOnlyList<IptvContinueSeries> GetContinueWatchingSeries(long sourceId, int limit=20)
    {
        using var c = Open();
        using var cmd = c.CreateCommand();
        cmd.CommandText = """
            WITH ranked AS (
                SELECT p.*, ROW_NUMBER() OVER (
                    PARTITION BY p.series_stable_key
                    ORDER BY p.last_watched_at DESC, p.id DESC
                ) AS ranking
                FROM iptv_vod_progress p
                WHERE p.source_id=$source AND p.media_type='episode' AND p.series_stable_key IS NOT NULL
            ), resolved AS (
                SELECT latest.position_seconds, latest.duration_seconds, latest.completed, latest.last_watched_at,
                       series.id AS series_id, series.name AS series_name, series.poster_url,
                       CASE WHEN latest.completed=1 THEN (
                           SELECT next.id FROM iptv_series_episodes next
                           WHERE next.series_id=current.series_id AND (
                               next.season_number>current.season_number OR
                               (next.season_number=current.season_number AND (
                                   COALESCE(next.episode_number,2147483647)>COALESCE(current.episode_number,2147483647) OR
                                   (COALESCE(next.episode_number,2147483647)=COALESCE(current.episode_number,2147483647) AND next.id>current.id)
                               ))
                           ) ORDER BY next.season_number,COALESCE(next.episode_number,2147483647),next.id LIMIT 1
                       ) ELSE current.id END AS target_episode_id
                FROM ranked latest
                JOIN iptv_series series ON series.source_id=latest.source_id AND series.stable_key=latest.series_stable_key
                JOIN iptv_series_episodes current ON current.source_id=latest.source_id AND current.stable_key=latest.stable_key
                LEFT JOIN iptv_vod_continue_dismissals dismissal
                  ON dismissal.source_id=latest.source_id
                 AND dismissal.media_type='series'
                 AND dismissal.stable_key=latest.series_stable_key
                WHERE latest.ranking=1
                  AND (dismissal.id IS NULL OR latest.last_watched_at>dismissal.dismissed_at)
            )
            SELECT resolved.series_id,resolved.series_name,resolved.poster_url,
                   episode.id,episode.season_number,episode.episode_number,episode.name,
                   resolved.position_seconds,resolved.duration_seconds,resolved.completed,resolved.last_watched_at
            FROM resolved
            JOIN iptv_series_episodes episode ON episode.id=resolved.target_episode_id
            ORDER BY resolved.last_watched_at DESC
            LIMIT $limit
            """;
        cmd.Parameters.AddWithValue("$source", sourceId);
        cmd.Parameters.AddWithValue("$limit", Math.Clamp(limit, 1, 20));
        using var reader = cmd.ExecuteReader();
        var result = new List<IptvContinueSeries>();
        while (reader.Read())
        {
            var completed = reader.GetInt64(9) == 1;
            var position = completed ? 0 : reader.GetDouble(7);
            var duration = completed ? 0 : reader.GetDouble(8);
            result.Add(new(reader.GetInt64(0), reader.GetString(1), Null(reader, 2), reader.GetInt64(3),
                reader.GetInt32(4), reader.IsDBNull(5) ? null : reader.GetInt32(5), reader.GetString(6),
                position, duration, Percent(position, duration), completed,
                DateTimeOffset.Parse(reader.GetString(10), CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind)));
        }
        return result;
    }

    public bool DismissContinueWatching(long sourceId, string kind, long mediaId)
    {
        var (table, mediaType) = kind switch
        {
            "movie" => ("iptv_movies", "movie"),
            "series" => ("iptv_series", "series"),
            _ => throw new IptvValidationException("vod_kind_invalid"),
        };
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = $"""
            INSERT INTO iptv_vod_continue_dismissals(source_id,media_type,stable_key,dismissed_at)
            SELECT source_id,$type,stable_key,$now FROM {table}
            WHERE id=$id AND source_id=$source
            ON CONFLICT(source_id,media_type,stable_key)
            DO UPDATE SET dismissed_at=excluded.dismissed_at
            """;
        command.Parameters.AddWithValue("$source", sourceId);
        command.Parameters.AddWithValue("$type", mediaType);
        command.Parameters.AddWithValue("$id", mediaId);
        command.Parameters.AddWithValue("$now", _utcNow().ToString("O", CultureInfo.InvariantCulture));
        return command.ExecuteNonQuery() == 1;
    }

    internal IptvVodPlaybackItem? NavigateEpisode(long sourceId, string stableKey, string direction)
    { using var c=Open();using var current=c.CreateCommand();current.CommandText="SELECT series_id,season_number,episode_number,id FROM iptv_series_episodes WHERE source_id=$source AND stable_key=$key";current.Parameters.AddWithValue("$source",sourceId);current.Parameters.AddWithValue("$key",stableKey);using var cr=current.ExecuteReader();if(!cr.Read())return null;var series=cr.GetInt64(0);var season=cr.GetInt32(1);var episode=cr.IsDBNull(2)?int.MaxValue:cr.GetInt32(2);var id=cr.GetInt64(3);cr.Close();using var cmd=c.CreateCommand();cmd.CommandText=direction=="next"?"SELECT id FROM iptv_series_episodes WHERE source_id=$source AND series_id=$series AND (season_number>$season OR (season_number=$season AND (COALESCE(episode_number,2147483647)>$episode OR (COALESCE(episode_number,2147483647)=$episode AND id>$id)))) ORDER BY season_number,COALESCE(episode_number,2147483647),id LIMIT 1":"SELECT id FROM iptv_series_episodes WHERE source_id=$source AND series_id=$series AND (season_number<$season OR (season_number=$season AND (COALESCE(episode_number,2147483647)<$episode OR (COALESCE(episode_number,2147483647)=$episode AND id<$id)))) ORDER BY season_number DESC,COALESCE(episode_number,2147483647) DESC,id DESC LIMIT 1";cmd.Parameters.AddWithValue("$source",sourceId);cmd.Parameters.AddWithValue("$series",series);cmd.Parameters.AddWithValue("$season",season);cmd.Parameters.AddWithValue("$episode",episode);cmd.Parameters.AddWithValue("$id",id);var target=cmd.ExecuteScalar();return target is null?null:ResolveEpisodeForPlayback(Convert.ToInt64(target,CultureInfo.InvariantCulture)); }

    internal static bool IsCompleted(double position,double duration)=>duration>0&&(position/duration>=.90||position>=duration-5);
    private static double Percent(double position,double duration)=>duration>0?Math.Clamp(position/duration*100,0,100):0;

    private static void Execute(SqliteConnection c, SqliteTransaction tx, string sql, long source) { using var cmd = c.CreateCommand(); cmd.Transaction = tx; cmd.CommandText = sql; cmd.Parameters.AddWithValue("$source", source); cmd.ExecuteNonQuery(); }
    private static void AddVodParameters(SqliteCommand cmd, long source, bool extension = true) { cmd.Parameters.AddWithValue("$s", source); foreach (var p in new[] { "$id", "$key", "$c", "$n", "$p", "$y", "$r", "$d", "$g", "$rd", "$a" }) cmd.Parameters.Add(new SqliteParameter(p, SqliteType.Text)); if (extension) cmd.Parameters.Add(new SqliteParameter("$e", SqliteType.Text)); }
    private static void BindVod(SqliteCommand cmd, string id, string key, string? category, string name, string? poster, string? extension, string? year, string? rating, string? plot, string? genre, string? release, string? added) { cmd.Parameters["$id"].Value = id; cmd.Parameters["$key"].Value = key; cmd.Parameters["$c"].Value = (object?)category ?? DBNull.Value; cmd.Parameters["$n"].Value = name; cmd.Parameters["$p"].Value = (object?)poster ?? DBNull.Value; if (cmd.Parameters.Contains("$e")) cmd.Parameters["$e"].Value = (object?)extension ?? DBNull.Value; cmd.Parameters["$y"].Value = (object?)year ?? DBNull.Value; cmd.Parameters["$r"].Value = (object?)rating ?? DBNull.Value; cmd.Parameters["$d"].Value = (object?)plot ?? DBNull.Value; cmd.Parameters["$g"].Value = (object?)genre ?? DBNull.Value; cmd.Parameters["$rd"].Value = (object?)release ?? DBNull.Value; cmd.Parameters["$a"].Value = (object?)added ?? DBNull.Value; }
    private static void BindSearch(SqliteCommand cmd, long source, string query, string? category, int offset, int limit) { cmd.Parameters.AddWithValue("$s", source); cmd.Parameters.AddWithValue("$q", query); cmd.Parameters.AddWithValue("$p", $"%{EscapeLike(query)}%"); cmd.Parameters.AddWithValue("$c", (object?)category ?? DBNull.Value); cmd.Parameters.AddWithValue("$o", offset); cmd.Parameters.AddWithValue("$l", limit + 1); }
    private static string? Null(SqliteDataReader r, int i) => r.IsDBNull(i) ? null : r.GetString(i);
    private static string VodType(IptvVodMediaType type) => type == IptvVodMediaType.Movie ? "movie" : "series";
}
