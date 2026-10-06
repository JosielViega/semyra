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

    public IptvStore(string databasePath, ISecretProtector protector)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(databasePath);
        _protector = protector;
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
            PRAGMA user_version = 1;
            """;
        command.ExecuteNonQuery();
    }

    public IptvSourceSummary AddSource(string name, IptvSourceType type, string location)
    {
        if (type is not (IptvSourceType.M3uUrl or IptvSourceType.M3uFile))
        {
            throw new ArgumentException("Tipo de fonte não suportado.", nameof(type));
        }

        using var connection = Open();
        using var command = connection.CreateCommand();
        var now = DateTimeOffset.UtcNow.ToString("O", CultureInfo.InvariantCulture);
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
            SELECT id, name, group_name, logo_url, tvg_id
            FROM iptv_channels
            WHERE source_id = $source
              AND ($query = '' OR name LIKE $pattern ESCAPE '\')
              AND ($group IS NULL OR group_name = $group COLLATE NOCASE)
            ORDER BY name COLLATE NOCASE, id
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
                reader.IsDBNull(4) ? null : reader.GetString(4)));
        }
        var hasMore = channels.Count > limit;
        if (hasMore)
        {
            channels.RemoveAt(channels.Count - 1);
        }
        return new IptvChannelSearchResult(channels, offset, limit, hasMore);
    }

    public void MarkRefreshing(long sourceId)
    {
        UpdateRefresh(sourceId, "refreshing", null, null);
    }

    public void MarkRefreshError(long sourceId, string error)
    {
        UpdateRefresh(sourceId, "error", error, DateTimeOffset.UtcNow);
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
                    (stable_key, external_id, name, group_name, logo_url, tvg_id, protected_stream_url)
                VALUES ($key, $external, $name, $group, $logo, $tvg, $stream)
                """;
            var key = insert.Parameters.Add("$key", SqliteType.Text);
            var external = insert.Parameters.Add("$external", SqliteType.Text);
            var name = insert.Parameters.Add("$name", SqliteType.Text);
            var group = insert.Parameters.Add("$group", SqliteType.Text);
            var logo = insert.Parameters.Add("$logo", SqliteType.Text);
            var tvg = insert.Parameters.Add("$tvg", SqliteType.Text);
            var stream = insert.Parameters.Add("$stream", SqliteType.Blob);

            await foreach (var channel in channels.WithCancellation(cancellationToken))
            {
                key.Value = StableKey(channel);
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

        var now = DateTimeOffset.UtcNow.ToString("O", CultureInfo.InvariantCulture);
        using (var replace = connection.CreateCommand())
        {
            replace.Transaction = transaction;
            replace.CommandText = """
                DELETE FROM iptv_channels WHERE source_id = $source;
                INSERT INTO iptv_channels
                    (source_id, stable_key, external_id, name, group_name, logo_url, tvg_id, protected_stream_url, created_at, updated_at)
                SELECT $source, stable_key, external_id, name, group_name, logo_url, tvg_id, protected_stream_url, $now, $now
                FROM temp.iptv_import;
                UPDATE iptv_sources
                SET refresh_status = 'ready', refresh_error = NULL, last_refresh_at = $now, updated_at = $now
                WHERE id = $source;
                """;
            replace.Parameters.AddWithValue("$source", sourceId);
            replace.Parameters.AddWithValue("$now", now);
            replace.ExecuteNonQuery();
        }
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
        command.Parameters.AddWithValue("$now", DateTimeOffset.UtcNow.ToString("O", CultureInfo.InvariantCulture));
        command.Parameters.AddWithValue("$id", sourceId);
        command.ExecuteNonQuery();
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
}
