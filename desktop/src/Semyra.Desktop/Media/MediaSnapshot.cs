namespace Semyra.Desktop.Media;

public sealed record MediaSnapshot(
    string State,
    long? ChannelId,
    string? ChannelName,
    int Attempt,
    string? LastErrorCode,
    string Mode = "local",
    string? PlaybackKind = null,
    string? SessionId = null,
    double Position = 0,
    double Duration = 0,
    bool Seekable = false,
    bool Muted = false,
    double Volume = 100,
    string? Container = null,
    string? VideoCodec = null,
    string? AudioCodec = null,
    string? MediaKind = null,
    bool Fullscreen = false,
    bool Paused = false,
    bool Buffering = false)
{
    public static MediaSnapshot Idle { get; } = new("idle", null, null, 0, null, "local");
}

public sealed class MediaEngineException : Exception
{
    public MediaEngineException(string code, Exception? innerException = null)
        : base(code, innerException)
    {
        Code = code;
    }

    public string Code { get; }
}
