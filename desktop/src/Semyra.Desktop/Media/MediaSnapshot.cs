namespace Semyra.Desktop.Media;

public sealed record MediaSnapshot(
    string State,
    long? ChannelId,
    string? ChannelName,
    int Attempt,
    string? LastErrorCode,
    string Mode = "local")
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
