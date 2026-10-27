namespace Semyra.MediaHost;

internal interface IMediaHostPublisher : IDisposable
{
    MediaHostSnapshot Start(string sessionId, Uri source, Uri whip);
    MediaHostSnapshot Pause(string sessionId);
    MediaHostSnapshot Play(string sessionId);
    MediaHostSnapshot Seek(string sessionId, long positionMs);
    MediaHostSnapshot Snapshot(string? sessionId = null);
    MediaHostSnapshot Stop(string? sessionId = null);
}

internal static class MediaHostServer
{
    internal static MediaHostSnapshot Handle(MediaHostCommand command, IMediaHostPublisher publisher) => command.Command switch
    {
        "start" => publisher.Start(command.SessionId!, new Uri(command.SourceUrl!), new Uri(command.WhipEndpoint!)),
        "pause" => publisher.Pause(command.SessionId!),
        "play" => publisher.Play(command.SessionId!),
        "seek" => publisher.Seek(command.SessionId!, command.PositionMs!.Value),
        "snapshot" => publisher.Snapshot(command.SessionId),
        "stop" => publisher.Stop(command.SessionId),
        _ => new(false, "failed", 0, 0, false, command.SessionId, "unknown_command"),
    };
}
