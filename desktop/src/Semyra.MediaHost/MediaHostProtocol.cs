using System.Text.Json;

namespace Semyra.MediaHost;

public sealed record MediaHostCommand(
    string Command,
    string? SessionId = null,
    string? SourceUrl = null,
    string? WhipEndpoint = null,
    long? PositionMs = null);

public sealed record MediaHostSnapshot(
    bool Ok,
    string State,
    long PositionMs,
    long DurationMs,
    bool Seekable,
    string? SessionId,
    string? ErrorCode = null,
    RtpPayloaderStats? VideoRtp = null,
    RtpPayloaderStats? AudioRtp = null);

public sealed record RtpPayloaderStats(uint Timestamp, ulong RunningTimeNs, uint Seqnum, uint Ssrc);

public static class MediaHostProtocol
{
    private static readonly JsonSerializerOptions Options = new(JsonSerializerDefaults.Web);

    public static bool TryParse(string line, out MediaHostCommand command)
    {
        command = new MediaHostCommand(string.Empty);
        if (string.IsNullOrWhiteSpace(line) || line.Length > 32_768) return false;
        try
        {
            using var document = JsonDocument.Parse(line);
            if (document.RootElement.ValueKind != JsonValueKind.Object) return false;
            var names = document.RootElement.EnumerateObject().Select(property => property.Name).ToHashSet(StringComparer.Ordinal);
            if (!document.RootElement.TryGetProperty("command", out var commandValue)
                || commandValue.ValueKind != JsonValueKind.String) return false;
            var action = commandValue.GetString();
            if (action is not ("start" or "pause" or "play" or "seek" or "snapshot" or "stop")) return false;

            string? sessionId = ReadString(document.RootElement, "sessionId");
            string? sourceUrl = ReadString(document.RootElement, "sourceUrl");
            string? whipEndpoint = ReadString(document.RootElement, "whipEndpoint");
            long? positionMs = ReadInt64(document.RootElement, "positionMs");
            var allowed = action switch
            {
                "start" => new[] { "command", "sessionId", "sourceUrl", "whipEndpoint" },
                "seek" => new[] { "command", "sessionId", "positionMs" },
                _ => new[] { "command", "sessionId" },
            };
            if (names.Except(allowed, StringComparer.Ordinal).Any()) return false;
            if (sessionId is null || !System.Text.RegularExpressions.Regex.IsMatch(sessionId, "^[a-f0-9]{32}$")) return false;
            if (action == "start")
            {
                if (!Uri.TryCreate(sourceUrl, UriKind.Absolute, out var source) || !source.IsLoopback || source.Scheme != Uri.UriSchemeHttp) return false;
                if (!Uri.TryCreate(whipEndpoint, UriKind.Absolute, out var whip) || whip.Scheme != Uri.UriSchemeHttps) return false;
            }
            else if (sourceUrl is not null || whipEndpoint is not null) return false;
            if (action == "seek" && (positionMs is null || positionMs < 0 || positionMs > 315_576_000_000)) return false;
            if (action != "seek" && positionMs is not null) return false;
            command = new MediaHostCommand(action, sessionId, sourceUrl, whipEndpoint, positionMs);
            return true;
        }
        catch (JsonException) { return false; }
    }

    public static string Serialize(MediaHostSnapshot snapshot) => JsonSerializer.Serialize(snapshot, Options);

    private static string? ReadString(JsonElement root, string name) =>
        root.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() : null;

    private static long? ReadInt64(JsonElement root, string name) =>
        root.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.Number && value.TryGetInt64(out var parsed) ? parsed : null;
}
