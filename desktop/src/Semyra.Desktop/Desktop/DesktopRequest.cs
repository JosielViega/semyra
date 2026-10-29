using Semyra.Desktop.Host;

namespace Semyra.Desktop.Desktop;

public sealed record DesktopRequest(
    string Type,
    string RequestId,
    HostAuthorization? Authorization = null,
    IptvRequest? Iptv = null,
    MediaRequest? Media = null,
    AccountRequest? Account = null,
    PlayerRequest? Player = null,
    WindowRequest? Window = null,
    ScreenRequest? Screen = null);

public sealed record ScreenRequest(
    string AccountContextId,
    string? TargetId = null,
    string? Quality = null,
    bool Audio = false);

public sealed record WindowRequest(bool? Enabled = null);

public sealed record AccountRequest(string? ProfileId = null);

public sealed record IptvRequest(
    long? SourceId = null,
    string? Name = null,
    string? Location = null,
    string? Query = null,
    string? Group = null,
    int Offset = 0,
    int Limit = 50,
    long? ChannelId = null,
    long? RecentId = null,
    bool? Favorite = null,
    string? AccountContextId = null,
    string? BaseUrl = null,
    string? Username = null,
    string? Password = null,
    string? MediaType = null,
    string? Category = null,
    long? SeriesId = null,
    long? MediaId = null,
    bool Refresh = false);

public sealed record MediaRequest(
    long? ChannelId = null,
    string? AccountContextId = null,
    string? Kind = null,
    long? MediaId = null,
    string? Direction = null,
    string? Action = null,
    long? PositionMs = null,
    string? TransmissionInstanceId = null,
    long? TransmissionRevision = null);

public sealed record PlayerRequest(
    string AccountContextId,
    string SessionId,
    string? Command = null,
    double? Value = null,
    double X = 0,
    double Y = 0,
    double Width = 0,
    double Height = 0,
    double ViewportWidth = 0,
    double ViewportHeight = 0,
    bool Visible = true,
    bool? Fullscreen = null);
