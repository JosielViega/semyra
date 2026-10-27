using Semyra.MediaHost;

using var publisher = new GStreamerVodPublisher();
string? line;
while ((line = await Console.In.ReadLineAsync()) is not null)
{
    MediaHostSnapshot response;
    if (!MediaHostProtocol.TryParse(line, out var command))
    {
        response = new(false, "failed", 0, 0, false, null, "invalid_command");
    }
    else
    {
        try
        {
            response = MediaHostServer.Handle(command, publisher);
        }
        catch (Exception) { response = new(false, "failed", 0, 0, false, command.SessionId, "media_host_failed"); }
    }
    await Console.Out.WriteLineAsync(MediaHostProtocol.Serialize(response));
    await Console.Out.FlushAsync();
}
