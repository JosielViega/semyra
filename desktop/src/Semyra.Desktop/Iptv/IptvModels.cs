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
    int ChannelCount);

public sealed record IptvChannelSummary(
    long Id,
    string Name,
    string? GroupName,
    string? LogoUrl,
    string? TvgId);

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

public sealed record ParsedM3uChannel(
    string Name,
    string StreamUrl,
    string? TvgId,
    string? LogoUrl,
    string? Group);
