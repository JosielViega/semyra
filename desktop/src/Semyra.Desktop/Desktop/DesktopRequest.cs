using Semyra.Desktop.Host;

namespace Semyra.Desktop.Desktop;

public sealed record DesktopRequest(
    string Type,
    string RequestId,
    HostAuthorization? Authorization = null,
    IptvRequest? Iptv = null,
    MediaRequest? Media = null,
    AccountRequest? Account = null);

public sealed record AccountRequest(string? ProfileId = null);

public sealed record IptvRequest(
    long? SourceId = null,
    string? Name = null,
    string? Location = null,
    string? Query = null,
    string? Group = null,
    int Offset = 0,
    int Limit = 50,
    string? AccountContextId = null);

public sealed record MediaRequest(long? ChannelId = null, string? AccountContextId = null);
