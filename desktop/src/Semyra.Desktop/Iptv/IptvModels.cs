namespace Semyra.Desktop.Iptv;

public enum IptvSourceType
{
    M3uUrl,
    M3uFile,
    Xtream,
}

public sealed record IptvSourceSummary(
    long Id,
    string Name,
    string Type,
    bool Enabled,
    string LastRefreshStatus,
    string? LastRefreshError,
    DateTimeOffset? LastRefreshAt,
    int ChannelCount,
    int MovieCount = 0,
    int SeriesCount = 0,
    string VodSyncState = "idle",
    string? VodSyncError = null,
    DateTimeOffset? VodLastSyncedAt = null);

public enum IptvVodMediaType { Movie, Series }

internal sealed record XtreamVodCategory(string Id, string Name, IptvVodMediaType MediaType, int DisplayOrder);
internal sealed record XtreamMovie(string StreamId, string Name, string? CategoryId, string? PosterUrl,
    string? ContainerExtension, string? Year, string? Rating, string? Plot, string? Genre, string? ReleaseDate, string? Added);
internal sealed record XtreamSeries(string SeriesId, string Name, string? CategoryId, string? PosterUrl,
    string? Year, string? Rating, string? Plot, string? Genre, string? ReleaseDate, string? Added);
internal sealed record XtreamEpisode(string EpisodeId, int SeasonNumber, int? EpisodeNumber, string Name,
    string? ContainerExtension, string? Duration, string? Plot, string? Added);
internal sealed record XtreamVodImport(
    IReadOnlyList<XtreamVodCategory> Categories,
    IReadOnlyList<XtreamMovie> Movies,
    IReadOnlyList<XtreamSeries> Series);

public sealed record IptvVodCategorySummary(long Id, string MediaType, string ExternalCategoryId, string Name, int DisplayOrder);
public sealed record IptvMovieSummary(long Id, string Name, string? CategoryId, string? PosterUrl, string? Year, string? Rating, string? Genre);
public sealed record IptvSeriesSummary(long Id, string Name, string? CategoryId, string? PosterUrl, string? Year, string? Rating, string? Genre);
public sealed record IptvVodSearchResult<T>(IReadOnlyList<T> Items, int Offset, int Limit, bool HasMore);
public sealed record IptvEpisodeSummary(long Id, int SeasonNumber, int? EpisodeNumber, string Name, string? Duration);
public sealed record IptvSeriesDetails(long SeriesId, IReadOnlyList<IptvEpisodeSummary> Episodes, DateTimeOffset? LastSyncedAt);
internal sealed record IptvVodPlaybackItem(long Id, long SourceId, string Name, string ExternalId, string? ContainerExtension, string Kind);

public sealed record IptvChannelSummary(
    long Id,
    string Name,
    string? GroupName,
    string? LogoUrl,
    string? TvgId,
    bool IsFavorite);

public sealed record IptvRecentSummary(
    long RecentId,
    long ChannelId,
    string Name,
    string? GroupName,
    string? LogoUrl,
    string? TvgId,
    bool IsFavorite);

public sealed record IptvChannelSearchResult(
    IReadOnlyList<IptvChannelSummary> Channels,
    int Offset,
    int Limit,
    bool HasMore);

internal sealed record IptvSourceRecord(
    long Id,
    string Name,
    IptvSourceType Type,
    byte[] ProtectedLocation);

internal sealed record IptvPlaybackChannel(
    long Id,
    long SourceId,
    string Name,
    string? GroupName,
    Uri StreamUri);

public sealed record ParsedM3uChannel(
    string Name,
    string StreamUrl,
    string? TvgId,
    string? LogoUrl,
    string? Group,
    string? ExternalId = null,
    string? StableIdentity = null);

internal sealed record XtreamSourceConfiguration(
    int Version,
    string BaseUrl,
    string Username,
    string Password);

internal sealed record XtreamCategory(string Id, string Name);

internal sealed record XtreamImportResult(
    IReadOnlyList<ParsedM3uChannel> Channels,
    int Received,
    int Skipped);
