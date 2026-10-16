using System.Globalization;
using System.IO;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Data.Sqlite;

namespace Semyra.Desktop.Iptv;

public sealed class IptvStore
{
    private readonly string _connectionString;
    private readonly ISecretProtector _protector;
    private readonly Func<DateTimeOffset> _utcNow;

    public IptvStore(string databasePath, ISecretProtector protector, Func<DateTimeOffset>? utcNow = null)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(databasePath);
        _protector = protector;
        _utcNow = utcNow ?? (() => DateTimeOffset.UtcNow);
        Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(databasePath))!);
        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = databasePath,
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared,
            Pooling = false,
        }.ToString();
    }

    public void Initialize()
    {
        using var connection = Open();
        using var versionCommand = connection.CreateCommand();
        versionCommand.CommandText = "PRAGMA user_version;";
        var version = Convert.ToInt32(versionCommand.ExecuteScalar(), CultureInfo.InvariantCulture);
        if (version == 0)
        {
            CreateVersionTwo(connection);
            return;
        }
        if (version == 1)
        {
            MigrateVersionOneToTwo(connection);
            return;
        }
        if (version != 2)
        {
            throw new InvalidDataException("Versão do catálogo IPTV local não suportada.");
        }
    }

    private static void CreateVersionTwo(SqliteConnection connection)
    {
        using var command = connection.CreateCommand();
        command.CommandText = """
            CREATE TABLE IF NOT EXISTS iptv_sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('m3u_url', 'm3u_file')),
                protected_location BLOB NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
                refresh_status TEXT NOT NULL DEFAULT 'never',
                refresh_error TEXT NULL,
                last_refresh_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS iptv_channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                stable_key TEXT NOT NULL,
                logical_key TEXT NOT NULL,
                external_id TEXT NULL,
                name TEXT NOT NULL,
                group_name TEXT NULL,
                logo_url TEXT NULL,
                tvg_id TEXT NULL,
                protected_stream_url BLOB NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                UNIQUE (source_id, stable_key)
            );
            CREATE INDEX IF NOT EXISTS idx_iptv_channels_source_group
                ON iptv_channels(source_id, group_name COLLATE NOCASE);
            CREATE INDEX IF NOT EXISTS idx_iptv_channels_source_name
                ON iptv_channels(source_id, name COLLATE NOCASE);
            CREATE INDEX IF NOT EXISTS idx_iptv_channels_source_logical
                ON iptv_channels(source_id, logical_key);
            CREATE TABLE IF NOT EXISTS iptv_favorites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                stable_key TEXT NOT NULL,
                logical_key TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                UNIQUE (source_id, stable_key)
            );
            CREATE INDEX IF NOT EXISTS idx_iptv_favorites_source_logical
                ON iptv_favorites(source_id, logical_key);
            CREATE TABLE IF NOT EXISTS iptv_recents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL,
                stable_key TEXT NOT NULL,
                logical_key TEXT NOT NULL,
                last_watched_at TEXT NOT NULL,
                FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                UNIQUE (source_id, stable_key)
            );
            CREATE INDEX IF NOT EXISTS idx_iptv_recents_source_watched
                ON iptv_recents(source_id, last_watched_at DESC, id DESC);
            CREATE INDEX IF NOT EXISTS idx_iptv_recents_source_logical
                ON iptv_recents(source_id, logical_key);
            PRAGMA user_version = 2;
            """;
        command.ExecuteNonQuery();
    }

    private static void MigrateVersionOneToTwo(SqliteConnection connection)
    {
        using var transaction = connection.BeginTransaction();
        if (!ColumnExists(connection, transaction, "iptv_channels", "logical_key"))
        {
            using var alter = connection.CreateCommand();
            alter.Transaction = transaction;
            alter.CommandText = "ALTER TABLE iptv_channels ADD COLUMN logical_key TEXT NULL;";
            alter.ExecuteNonQuery();
        }

        var channels = new List<(long Id, string? TvgId, string Name, string? Group)>();
        using (var read = connection.CreateCommand())
        {
            read.Transaction = transaction;
            read.CommandText = "SELECT id, tvg_id, name, group_name FROM iptv_channels WHERE logical_key IS NULL OR logical_key = ''";
            using var reader = read.ExecuteReader();
            while (reader.Read())
            {
                channels.Add((
                    reader.GetInt64(0),
                    reader.IsDBNull(1) ? null : reader.GetString(1),
                    reader.GetString(2),
                    reader.IsDBNull(3) ? null : reader.GetString(3)));
            }
        }
        using (var update = connection.CreateCommand())
        {
            update.Transaction = transaction;
            update.CommandText = "UPDATE iptv_channels SET logical_key = $logical WHERE id = $id";
            var logical = update.Parameters.Add("$logical", SqliteType.Text);
            var id = update.Parameters.Add("$id", SqliteType.Integer);
            foreach (var channel in channels)
            {
                logical.Value = LogicalChannelIdentity.Create(channel.TvgId, channel.Name, channel.Group);
                id.Value = channel.Id;
                update.ExecuteNonQuery();
            }
        }
        using (var schema = connection.CreateCommand())
        {
            schema.Transaction = transaction;
            schema.CommandText = """
                CREATE INDEX IF NOT EXISTS idx_iptv_channels_source_logical
                    ON iptv_channels(source_id, logical_key);
                CREATE TABLE IF NOT EXISTS iptv_favorites (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    source_id INTEGER NOT NULL,
                    stable_key TEXT NOT NULL,
                    logical_key TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                    UNIQUE (source_id, stable_key)
                );
                CREATE INDEX IF NOT EXISTS idx_iptv_favorites_source_logical
                    ON iptv_favorites(source_id, logical_key);
                CREATE TABLE IF NOT EXISTS iptv_recents (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    source_id INTEGER NOT NULL,
                    stable_key TEXT NOT NULL,
                    logical_key TEXT NOT NULL,
                    last_watched_at TEXT NOT NULL,
                    FOREIGN KEY (source_id) REFERENCES iptv_sources(id) ON DELETE CASCADE,
                    UNIQUE (source_id, stable_key)
                );
                CREATE INDEX IF NOT EXISTS idx_iptv_recents_source_watched
                    ON iptv_recents(source_id, last_watched_at DESC, id DESC);
                CREATE INDEX IF NOT EXISTS idx_iptv_recents_source_logical
                    ON iptv_recents(source_id, logical_key);
                PRAGMA user_version = 2;
                """;
            schema.ExecuteNonQuery();
        }
        transaction.Commit();
    }

    private static bool ColumnExists(SqliteConnection connection, SqliteTransaction transaction, string table, string column)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = $"SELECT COUNT(*) FROM pragma_table_info('{table}') WHERE name = $column";
        command.Parameters.AddWithValue("$column", column);
        return Convert.ToInt64(command.ExecuteScalar(), CultureInfo.InvariantCulture) == 1;
    }

    public IptvSourceSummary AddSource(string name, IptvSourceType type, string location)
    {
        if (type is not (IptvSourceType.M3uUrl or IptvSourceType.M3uFile))
        {
            throw new ArgumentException("Tipo de fonte não suportado.", nameof(type));
        }

        using var connection = Open();
        using var command = connection.CreateCommand();
        var now = _utcNow().ToString("O", CultureInfo.InvariantCulture);
        command.CommandText = """
            INSERT INTO iptv_sources
                (name, type, protected_location, enabled, refresh_status, created_at, updated_at)
            VALUES
                ($name, $type, $location, 1, 'never', $now, $now);
            SELECT last_insert_rowid();
            """;
        command.Parameters.AddWithValue("$name", name);
        command.Parameters.AddWithValue("$type", TypeValue(type));
        command.Parameters.Add("$location", SqliteType.Blob).Value = _protector.Protect(location);
        command.Parameters.AddWithValue("$now", now);
        var id = (long)(command.ExecuteScalar() ?? throw new InvalidOperationException("A fonte não foi criada."));
        return FindSummary(connection, id) ?? throw new InvalidOperationException("A fonte não foi criada.");
    }

    public bool RemoveSource(long sourceId)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = "DELETE FROM iptv_sources WHERE id = $id";
        command.Parameters.AddWithValue("$id", sourceId);
        return command.ExecuteNonQuery() == 1;
    }

    public IReadOnlyList<IptvSourceSummary> ListSources()
    {
        using var connection = Open();
        using var command = SourceSummaryCommand(connection);
        command.CommandText += " ORDER BY s.name COLLATE NOCASE, s.id";
        using var reader = command.ExecuteReader();
        var sources = new List<IptvSourceSummary>();
        while (reader.Read())
        {
            sources.Add(ReadSummary(reader));
        }
        return sources;
    }

    internal IptvSourceRecord? FindSource(long sourceId)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = "SELECT id, name, type, protected_location FROM iptv_sources WHERE id = $id";
        command.Parameters.AddWithValue("$id", sourceId);
        using var reader = command.ExecuteReader();
        if (!reader.Read())
        {
            return null;
        }
        return new IptvSourceRecord(
            reader.GetInt64(0),
            reader.GetString(1),
            ParseType(reader.GetString(2)),
            (byte[])reader[3]);
    }

    internal IptvPlaybackChannel ResolveChannelForPlayback(long channelId)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT c.id, c.source_id, c.name, c.group_name, c.protected_stream_url,
                   s.enabled, s.refresh_status
            FROM iptv_channels c
            INNER JOIN iptv_sources s ON s.id = c.source_id
            WHERE c.id = $id
            """;
        command.Parameters.AddWithValue("$id", channelId);
        using var reader = command.ExecuteReader();
        if (!reader.Read())
        {
            throw new IptvPlaybackException("channel_not_found");
        }
        if (reader.GetInt64(5) != 1 || !string.Equals(reader.GetString(6), "ready", StringComparison.Ordinal))
        {
            throw new IptvPlaybackException("source_unavailable");
        }

        string plaintext;
        try
        {
            plaintext = _protector.Unprotect((byte[])reader[4]);
        }
        catch (CryptographicException exception)
        {
            throw new IptvPlaybackException("source_unavailable", exception);
        }
        if (!Uri.TryCreate(plaintext, UriKind.Absolute, out var streamUri)
            || streamUri.Scheme is not ("http" or "https"))
        {
            throw new IptvPlaybackException("source_unavailable");
        }

        return new IptvPlaybackChannel(
            reader.GetInt64(0),
            reader.GetInt64(1),
            reader.GetString(2),
            reader.IsDBNull(3) ? null : reader.GetString(3),
            streamUri);
    }

    public IReadOnlyList<string> GetGroups(long sourceId)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT DISTINCT group_name
            FROM iptv_channels
            WHERE source_id = $source AND group_name IS NOT NULL AND group_name <> ''
            ORDER BY group_name COLLATE NOCASE
            """;
        command.Parameters.AddWithValue("$source", sourceId);
        using var reader = command.ExecuteReader();
        var groups = new List<string>();
        while (reader.Read())
        {
            groups.Add(reader.GetString(0));
        }
        return groups;
    }

    public IptvChannelSearchResult SearchChannels(
        long sourceId,
        string query,
        string? group,
        int offset,
        int limit)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT c.id, c.name, c.group_name, c.logo_url, c.tvg_id,
                   EXISTS (
                       SELECT 1 FROM iptv_favorites f
                       WHERE f.source_id = c.source_id
                         AND (
                             f.stable_key = c.stable_key
                             OR (
                                 f.logical_key = c.logical_key
                                 AND NOT EXISTS (
                                     SELECT 1 FROM iptv_channels exact
                                     WHERE exact.source_id = f.source_id AND exact.stable_key = f.stable_key
                                 )
                                 AND 1 = (
                                     SELECT COUNT(*) FROM iptv_channels candidate
                                     WHERE candidate.source_id = f.source_id AND candidate.logical_key = f.logical_key
                                 )
                             )
                         )
                   ) AS is_favorite
            FROM iptv_channels c
            WHERE c.source_id = $source
              AND ($query = '' OR c.name LIKE $pattern ESCAPE '\')
              AND ($group IS NULL OR c.group_name = $group COLLATE NOCASE)
            ORDER BY c.name COLLATE NOCASE, c.id
            LIMIT $limit OFFSET $offset
            """;
        command.Parameters.AddWithValue("$source", sourceId);
        command.Parameters.AddWithValue("$query", query);
        command.Parameters.AddWithValue("$pattern", $"%{EscapeLike(query)}%");
        command.Parameters.AddWithValue("$group", group is null ? DBNull.Value : group);
        command.Parameters.AddWithValue("$limit", limit + 1);
        command.Parameters.AddWithValue("$offset", offset);
        using var reader = command.ExecuteReader();
        var channels = new List<IptvChannelSummary>();
        while (reader.Read())
        {
            channels.Add(new IptvChannelSummary(
                reader.GetInt64(0),
                reader.GetString(1),
                reader.IsDBNull(2) ? null : reader.GetString(2),
                reader.IsDBNull(3) ? null : reader.GetString(3),
                reader.IsDBNull(4) ? null : reader.GetString(4),
                reader.GetInt64(5) == 1));
        }
        var hasMore = channels.Count > limit;
        if (hasMore)
        {
            channels.RemoveAt(channels.Count - 1);
        }
        return new IptvChannelSearchResult(channels, offset, limit, hasMore);
    }

    public bool SetFavorite(long channelId, bool favorite)
    {
        var channel = FindChannelIdentity(channelId);
        RebindPreferences(channel.SourceId);
        using var connection = Open();
        using var command = connection.CreateCommand();
        if (favorite)
        {
            command.CommandText = """
                INSERT INTO iptv_favorites (source_id, stable_key, logical_key, created_at)
                VALUES ($source, $stable, $logical, $created)
                ON CONFLICT(source_id, stable_key) DO NOTHING
                """;
            command.Parameters.AddWithValue("$created", _utcNow().ToString("O", CultureInfo.InvariantCulture));
        }
        else
        {
            command.CommandText = "DELETE FROM iptv_favorites WHERE source_id = $source AND stable_key = $stable";
        }
        command.Parameters.AddWithValue("$source", channel.SourceId);
        command.Parameters.AddWithValue("$stable", channel.StableKey);
        command.Parameters.AddWithValue("$logical", channel.LogicalKey);
        command.ExecuteNonQuery();
        return favorite;
    }

    public IptvChannelSearchResult ListFavorites(long sourceId, int offset, int limit)
    {
        RebindPreferences(sourceId);
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT c.id, c.name, c.group_name, c.logo_url, c.tvg_id, 1
            FROM iptv_favorites f
            INNER JOIN iptv_channels c
                ON c.source_id = f.source_id AND c.stable_key = f.stable_key
            WHERE f.source_id = $source
            ORDER BY f.created_at DESC, f.id DESC
            LIMIT $limit OFFSET $offset
            """;
        command.Parameters.AddWithValue("$source", sourceId);
        command.Parameters.AddWithValue("$limit", limit + 1);
        command.Parameters.AddWithValue("$offset", offset);
        using var reader = command.ExecuteReader();
        var channels = ReadChannels(reader);
        var hasMore = channels.Count > limit;
        if (hasMore)
        {
            channels.RemoveAt(channels.Count - 1);
        }
        return new IptvChannelSearchResult(channels, offset, limit, hasMore);
    }

    public IReadOnlyList<IptvRecentSummary> ListRecents(long sourceId)
    {
        RebindPreferences(sourceId);
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT r.id, c.id, c.name, c.group_name, c.logo_url, c.tvg_id,
                   EXISTS (
                       SELECT 1 FROM iptv_favorites f
                       WHERE f.source_id = c.source_id AND f.stable_key = c.stable_key
                   )
            FROM iptv_recents r
            INNER JOIN iptv_channels c
                ON c.source_id = r.source_id AND c.stable_key = r.stable_key
            WHERE r.source_id = $source
            ORDER BY r.last_watched_at DESC, r.id DESC
            LIMIT 20
            """;
        command.Parameters.AddWithValue("$source", sourceId);
        using var reader = command.ExecuteReader();
        var recents = new List<IptvRecentSummary>();
        while (reader.Read())
        {
            recents.Add(new IptvRecentSummary(
                reader.GetInt64(0),
                reader.GetInt64(1),
                reader.GetString(2),
                reader.IsDBNull(3) ? null : reader.GetString(3),
                reader.IsDBNull(4) ? null : reader.GetString(4),
                reader.IsDBNull(5) ? null : reader.GetString(5),
                reader.GetInt64(6) == 1));
        }
        return recents;
    }

    public void RecordRecent(long channelId)
    {
        var channel = FindChannelIdentity(channelId);
        RebindPreferences(channel.SourceId);
        using var connection = Open();
        using var transaction = connection.BeginTransaction();
        using (var upsert = connection.CreateCommand())
        {
            upsert.Transaction = transaction;
            upsert.CommandText = """
                INSERT INTO iptv_recents (source_id, stable_key, logical_key, last_watched_at)
                VALUES ($source, $stable, $logical, $watched)
                ON CONFLICT(source_id, stable_key) DO UPDATE SET
                    logical_key = excluded.logical_key,
                    last_watched_at = excluded.last_watched_at
                """;
            upsert.Parameters.AddWithValue("$source", channel.SourceId);
            upsert.Parameters.AddWithValue("$stable", channel.StableKey);
            upsert.Parameters.AddWithValue("$logical", channel.LogicalKey);
            upsert.Parameters.AddWithValue("$watched", _utcNow().ToString("O", CultureInfo.InvariantCulture));
            upsert.ExecuteNonQuery();
        }
        using (var trim = connection.CreateCommand())
        {
            trim.Transaction = transaction;
            trim.CommandText = """
                DELETE FROM iptv_recents
                WHERE source_id = $source
                  AND id NOT IN (
                      SELECT id FROM iptv_recents
                      WHERE source_id = $source
                      ORDER BY last_watched_at DESC, id DESC
                      LIMIT 20
                  )
                """;
            trim.Parameters.AddWithValue("$source", channel.SourceId);
            trim.ExecuteNonQuery();
        }
        transaction.Commit();
    }

    public bool RemoveRecent(long sourceId, long recentId)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = "DELETE FROM iptv_recents WHERE id = $recent AND source_id = $source";
        command.Parameters.AddWithValue("$recent", recentId);
        command.Parameters.AddWithValue("$source", sourceId);
        return command.ExecuteNonQuery() == 1;
    }

    public int ClearRecents(long sourceId)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = "DELETE FROM iptv_recents WHERE source_id = $source";
        command.Parameters.AddWithValue("$source", sourceId);
        return command.ExecuteNonQuery();
    }

    public void MarkRefreshing(long sourceId)
    {
        UpdateRefresh(sourceId, "refreshing", null, null);
    }

    public void MarkRefreshError(long sourceId, string error)
    {
        UpdateRefresh(sourceId, "error", error, _utcNow());
    }

    public async Task<IptvSourceSummary> ReplaceChannelsAsync(
        long sourceId,
        IAsyncEnumerable<ParsedM3uChannel> channels,
        CancellationToken cancellationToken)
    {
        using var connection = Open();
        using var transaction = connection.BeginTransaction();
        using (var create = connection.CreateCommand())
        {
            create.Transaction = transaction;
            create.CommandText = """
                DROP TABLE IF EXISTS temp.iptv_import;
                CREATE TEMP TABLE iptv_import (
                    stable_key TEXT PRIMARY KEY,
                    logical_key TEXT NOT NULL,
                    external_id TEXT NULL,
                    name TEXT NOT NULL,
                    group_name TEXT NULL,
                    logo_url TEXT NULL,
                    tvg_id TEXT NULL,
                    protected_stream_url BLOB NOT NULL
                );
                """;
            create.ExecuteNonQuery();
        }

        var count = 0;
        using (var insert = connection.CreateCommand())
        {
            insert.Transaction = transaction;
            insert.CommandText = """
                INSERT OR IGNORE INTO temp.iptv_import
                    (stable_key, logical_key, external_id, name, group_name, logo_url, tvg_id, protected_stream_url)
                VALUES ($key, $logical, $external, $name, $group, $logo, $tvg, $stream)
                """;
            var key = insert.Parameters.Add("$key", SqliteType.Text);
            var logical = insert.Parameters.Add("$logical", SqliteType.Text);
            var external = insert.Parameters.Add("$external", SqliteType.Text);
            var name = insert.Parameters.Add("$name", SqliteType.Text);
            var group = insert.Parameters.Add("$group", SqliteType.Text);
            var logo = insert.Parameters.Add("$logo", SqliteType.Text);
            var tvg = insert.Parameters.Add("$tvg", SqliteType.Text);
            var stream = insert.Parameters.Add("$stream", SqliteType.Blob);

            await foreach (var channel in channels.WithCancellation(cancellationToken).ConfigureAwait(false))
            {
                key.Value = StableKey(channel);
                logical.Value = LogicalChannelIdentity.Create(channel.TvgId, channel.Name, channel.Group);
                external.Value = (object?)channel.TvgId ?? DBNull.Value;
                name.Value = channel.Name;
                group.Value = (object?)channel.Group ?? DBNull.Value;
                logo.Value = (object?)channel.LogoUrl ?? DBNull.Value;
                tvg.Value = (object?)channel.TvgId ?? DBNull.Value;
                stream.Value = _protector.Protect(channel.StreamUrl);
                count += insert.ExecuteNonQuery();
            }
        }

        if (count == 0)
        {
            throw new InvalidDataException("A playlist não contém canais válidos.");
        }

        var now = _utcNow().ToString("O", CultureInfo.InvariantCulture);
        using (var replace = connection.CreateCommand())
        {
            replace.Transaction = transaction;
            replace.CommandText = """
                DELETE FROM iptv_channels WHERE source_id = $source;
                INSERT INTO iptv_channels
                    (source_id, stable_key, logical_key, external_id, name, group_name, logo_url, tvg_id, protected_stream_url, created_at, updated_at)
                SELECT $source, stable_key, logical_key, external_id, name, group_name, logo_url, tvg_id, protected_stream_url, $now, $now
                FROM temp.iptv_import;
                UPDATE iptv_sources
                SET refresh_status = 'ready', refresh_error = NULL, last_refresh_at = $now, updated_at = $now
                WHERE id = $source;
                """;
            replace.Parameters.AddWithValue("$source", sourceId);
            replace.Parameters.AddWithValue("$now", now);
            replace.ExecuteNonQuery();
        }
        RebindPreferences(connection, transaction, sourceId);
        transaction.Commit();
        return FindSummary(connection, sourceId) ?? throw new InvalidOperationException("A fonte não existe.");
    }

    private void UpdateRefresh(long sourceId, string status, string? error, DateTimeOffset? refreshedAt)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            UPDATE iptv_sources
            SET refresh_status = $status,
                refresh_error = $error,
                last_refresh_at = COALESCE($refreshed, last_refresh_at),
                updated_at = $now
            WHERE id = $id
            """;
        command.Parameters.AddWithValue("$status", status);
        command.Parameters.AddWithValue("$error", error is null ? DBNull.Value : error);
        command.Parameters.AddWithValue("$refreshed", refreshedAt is null ? DBNull.Value : refreshedAt.Value.ToString("O", CultureInfo.InvariantCulture));
        command.Parameters.AddWithValue("$now", _utcNow().ToString("O", CultureInfo.InvariantCulture));
        command.Parameters.AddWithValue("$id", sourceId);
        command.ExecuteNonQuery();
    }

    private ChannelIdentity FindChannelIdentity(long channelId)
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = "SELECT source_id, stable_key, logical_key FROM iptv_channels WHERE id = $id";
        command.Parameters.AddWithValue("$id", channelId);
        using var reader = command.ExecuteReader();
        if (!reader.Read())
        {
            throw new IptvValidationException("Canal não encontrado.");
        }
        return new ChannelIdentity(reader.GetInt64(0), reader.GetString(1), reader.GetString(2));
    }

    private void RebindPreferences(long sourceId)
    {
        using var connection = Open();
        using var transaction = connection.BeginTransaction();
        RebindPreferences(connection, transaction, sourceId);
        transaction.Commit();
    }

    private static void RebindPreferences(SqliteConnection connection, SqliteTransaction transaction, long sourceId)
    {
        foreach (var table in new[] { "iptv_favorites", "iptv_recents" })
        {
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = $"""
                UPDATE OR IGNORE {table}
                SET stable_key = (
                    SELECT MIN(candidate.stable_key)
                    FROM iptv_channels candidate
                    WHERE candidate.source_id = {table}.source_id
                      AND candidate.logical_key = {table}.logical_key
                )
                WHERE source_id = $source
                  AND NOT EXISTS (
                      SELECT 1 FROM iptv_channels exact
                      WHERE exact.source_id = {table}.source_id
                        AND exact.stable_key = {table}.stable_key
                  )
                  AND 1 = (
                      SELECT COUNT(*) FROM iptv_channels candidate
                      WHERE candidate.source_id = {table}.source_id
                        AND candidate.logical_key = {table}.logical_key
                  )
                """;
            command.Parameters.AddWithValue("$source", sourceId);
            command.ExecuteNonQuery();
        }
    }

    private static List<IptvChannelSummary> ReadChannels(SqliteDataReader reader)
    {
        var channels = new List<IptvChannelSummary>();
        while (reader.Read())
        {
            channels.Add(new IptvChannelSummary(
                reader.GetInt64(0),
                reader.GetString(1),
                reader.IsDBNull(2) ? null : reader.GetString(2),
                reader.IsDBNull(3) ? null : reader.GetString(3),
                reader.IsDBNull(4) ? null : reader.GetString(4),
                reader.GetInt64(5) == 1));
        }
        return channels;
    }

    private SqliteConnection Open()
    {
        var connection = new SqliteConnection(_connectionString);
        connection.Open();
        using var command = connection.CreateCommand();
        command.CommandText = "PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;";
        command.ExecuteNonQuery();
        return connection;
    }

    private static SqliteCommand SourceSummaryCommand(SqliteConnection connection)
    {
        var command = connection.CreateCommand();
        command.CommandText = """
            SELECT s.id, s.name, s.type, s.enabled, s.refresh_status, s.refresh_error,
                   s.last_refresh_at, COUNT(c.id)
            FROM iptv_sources s
            LEFT JOIN iptv_channels c ON c.source_id = s.id
            GROUP BY s.id
            """;
        return command;
    }

    private static IptvSourceSummary? FindSummary(SqliteConnection connection, long sourceId)
    {
        using var command = SourceSummaryCommand(connection);
        command.CommandText += " HAVING s.id = $id";
        command.Parameters.AddWithValue("$id", sourceId);
        using var reader = command.ExecuteReader();
        return reader.Read() ? ReadSummary(reader) : null;
    }

    private static IptvSourceSummary ReadSummary(SqliteDataReader reader)
    {
        DateTimeOffset? refreshed = null;
        if (!reader.IsDBNull(6)
            && DateTimeOffset.TryParse(reader.GetString(6), CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind, out var parsed))
        {
            refreshed = parsed;
        }
        return new IptvSourceSummary(
            reader.GetInt64(0), reader.GetString(1), reader.GetString(2), reader.GetInt64(3) == 1,
            reader.GetString(4), reader.IsDBNull(5) ? null : reader.GetString(5), refreshed,
            checked((int)reader.GetInt64(7)));
    }

    private static string TypeValue(IptvSourceType type) => type switch
    {
        IptvSourceType.M3uUrl => "m3u_url",
        IptvSourceType.M3uFile => "m3u_file",
        _ => throw new ArgumentOutOfRangeException(nameof(type)),
    };

    private static IptvSourceType ParseType(string value) => value switch
    {
        "m3u_url" => IptvSourceType.M3uUrl,
        "m3u_file" => IptvSourceType.M3uFile,
        _ => throw new InvalidDataException("Tipo de fonte inválido."),
    };

    private static string StableKey(ParsedM3uChannel channel)
    {
        var value = $"{channel.TvgId}\n{channel.Name}\n{channel.Group}\n{channel.StreamUrl}";
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(value))).ToLowerInvariant();
    }

    private static string EscapeLike(string value) => value.Replace("\\", "\\\\").Replace("%", "\\%").Replace("_", "\\_");

    private sealed record ChannelIdentity(long SourceId, string StableKey, string LogicalKey);
}

internal sealed class IptvPlaybackException : Exception
{
    public IptvPlaybackException(string code, Exception? innerException = null)
        : base(code, innerException)
    {
        Code = code;
    }

    public string Code { get; }
}
